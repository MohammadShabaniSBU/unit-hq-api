<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\AssertsAgentToolWriteGuard;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class AgentToolWriteGuardTest extends TestCase
{
    use AssertsAgentToolWriteGuard;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function no_tool_writes_forbidden_tables(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $this->startWriteGuard();

        foreach (app(ToolRegistry::class)->all() as $key => $tool) {
            $agent = match (true) {
                str_starts_with($key, 'identity.') => 'concierge',
                in_array($key, [
                    'contract.summary',
                    'billing.balance',
                    'billing.next_charge',
                    'billing.invoices',
                    'access.status',
                    'crm.create_note',
                ], true) => 'support',
                default => 'sales',
            };

            $this->dispatchTool($agent, $key, $principal, $this->minimalArgs($key));
        }

        $this->assertNoForbiddenWrites();
    }

    #[Test]
    public function verified_tools_deny_before_handle_without_database_touch(): void
    {
        $verified = [
            'support' => [
                'contract.summary',
                'billing.balance',
                'billing.next_charge',
                'billing.invoices',
                'access.status',
                'crm.create_note',
            ],
        ];

        foreach ($verified['support'] as $key) {
            foreach ([
                AgentPrincipal::anonymous(null, 'en'),
                AgentPrincipal::channelAsserted(1, null, 'en'),
            ] as $principal) {
                DB::flushQueryLog();
                DB::enableQueryLog();

                $result = $this->dispatchTool('support', $key, $principal, $this->minimalArgs($key));

                $this->assertSame(ToolInvocationStatus::Denied, $result->status, $key);
                $this->assertSame(ToolDeniedReason::Verification, $result->deniedReason, $key);
                $this->assertSame([], DB::getQueryLog(), "Tool [{$key}] queried the database on verification deny.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalArgs(string $key): array
    {
        return match ($key) {
            'pricing.quote', 'sales.propose_offer' => ['unit_class_id' => 1, 'site_id' => 1],
            'pricing.discounts', 'facility.site_info' => ['site_id' => 1],
            'crm.create_contact' => ['first_name' => 'Ada'],
            'crm.create_deal' => ['contact_id' => 1],
            'crm.create_task' => [
                'title' => 'Call back',
                'related_to_type' => 'contact',
                'related_to_id' => 1,
            ],
            'crm.create_note' => [
                'content' => 'Asked about hours',
                'related_to_type' => 'contact',
                'related_to_id' => 1,
            ],
            'kb.faq_lookup' => ['key' => 'access_hours'],
            'facility.size_guide' => ['metric' => 'standard_boxes'],
            'agent.escalate' => ['reason' => 'customer_requested', 'summary' => 'Wants a person'],
            // Deliberately invalid: missing options so validation fails before handle()
            // and the all-tools sweep still treats offers as forbidden.
            'sales.create_offer' => ['deal_id' => 1],
            'sales.create_reservation' => ['deal_id' => 1],
            default => [],
        };
    }
}
