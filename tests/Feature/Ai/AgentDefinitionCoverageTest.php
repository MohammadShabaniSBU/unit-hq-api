<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\Contract;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\ToolRegistry;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class AgentDefinitionCoverageTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function every_seeded_agent_key_resolves_and_tool_keys_are_registered(): void
    {
        $this->seed(AiAgentSeeder::class);

        $registry = app(AgentRegistry::class);
        $tools = app(ToolRegistry::class);

        foreach (AiAgent::query()->pluck('key') as $key) {
            $definition = $registry->get($key);
            $this->assertSame($key, $definition->key());

            foreach ($definition->toolKeys() as $toolKey) {
                $this->assertTrue(
                    $tools->has($toolKey),
                    "Definition [{$key}] claims unregistered tool [{$toolKey}].",
                );
            }
        }
    }

    #[Test]
    public function concierge_verified_tools_are_gated(): void
    {
        $definition = app(AgentRegistry::class)->get('concierge');
        $tools = app(ToolRegistry::class);
        $principal = AgentPrincipal::channelAsserted(1, null, 'en');
        $checked = 0;

        foreach ($definition->toolKeys() as $toolKey) {
            if ($tools->get($toolKey)->requiredVerification() !== VerificationLevel::Verified) {
                continue;
            }

            $result = $this->dispatchTool('concierge', $toolKey, $principal);
            $this->assertSame(ToolInvocationStatus::Denied, $result->status, $toolKey);
            $this->assertSame(ToolDeniedReason::Verification, $result->deniedReason, $toolKey);
            $checked++;
        }

        $this->assertSame(5, $checked);
    }

    #[Test]
    public function legacy_definitions_stay_registered(): void
    {
        $registry = app(AgentRegistry::class);

        $this->assertCount(3, $registry->all());
        $this->assertSame('sales', $registry->get('sales')->key());
        $this->assertSame('support', $registry->get('support')->key());
        $this->assertSame('concierge', $registry->get('concierge')->key());
    }

    #[Test]
    public function concierge_tool_keys_are_pinned(): void
    {
        $this->assertSame([
            'facility.availability',
            'facility.find_sites',
            'facility.site_info',
            'facility.size_guide',
            'calendar.resolve',
            'pricing.quote',
            'pricing.discounts',
            'sales.propose_offer',
            'sales.create_offer',
            'sales.create_reservation',
            'crm.create_contact',
            'crm.create_deal',
            'crm.create_task',
            'contract.summary',
            'billing.balance',
            'billing.next_charge',
            'billing.invoices',
            'access.status',
            'kb.faq_lookup',
            'agent.escalate',
        ], app(AgentRegistry::class)->get('concierge')->toolKeys());
    }

    #[Test]
    public function concierge_is_eligible_for_every_contact_and_hides_account_tools_until_verified(): void
    {
        $definition = app(AgentRegistry::class)->get('concierge');
        $prospect = Contact::factory()->create();
        $tenant = Contact::factory()->create();
        Contract::factory()->create(['contact_id' => $tenant->id]);

        $this->assertTrue($definition->eligible(null, null));
        $this->assertTrue($definition->eligible($prospect, null));
        $this->assertTrue($definition->eligible($tenant, null));

        $anonymousCtx = $this->conciergeContext(AgentPrincipal::anonymous(null, 'en'));
        $assertedCtx = $this->conciergeContext(AgentPrincipal::channelAsserted($prospect->id, null, 'en'));
        $verifiedCtx = $this->conciergeContext(AgentPrincipal::verified($tenant->id, null, 'en'));

        $anonymous = $definition->systemPrompt($anonymousCtx);
        $asserted = $definition->systemPrompt($assertedCtx);
        $verified = $definition->systemPrompt($verifiedCtx);

        $this->assertNotSame($anonymous, $asserted);
        $this->assertNotSame($anonymous, $verified);
        $this->assertNotSame($asserted, $verified);
        $this->assertNotSame($definition->promptVersion($anonymousCtx), $definition->promptVersion($assertedCtx));
        $this->assertNotSame($definition->promptVersion($anonymousCtx), $definition->promptVersion($verifiedCtx));

        foreach ([$anonymous, $asserted] as $prompt) {
            $this->assertStringNotContainsString('billing.balance', $prompt);
            $this->assertStringNotContainsString('billing.invoices', $prompt);
            $this->assertStringNotContainsString('contract.summary', $prompt);
            $this->assertStringNotContainsString('access.status', $prompt);
        }
    }

    private function conciergeContext(AgentPrincipal $principal): AgentContext
    {
        $definition = app(AgentRegistry::class)->get('concierge');
        $agent = AiAgent::factory()->create(['key' => 'concierge-'.uniqid(), 'is_active' => true]);
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Webchat,
            'contact_id' => $principal->contactId,
            'verification_level' => $principal->verification,
        ]);

        return new AgentContext(
            $principal,
            ChannelProfile::for(AgentChannel::Webchat),
            $definition,
            $conversation,
            $agent,
        );
    }
}
