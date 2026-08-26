<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentChannelBinding;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentChannelBindingSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_the_four_demo_rows(): void
    {
        $this->seed(AiAgentSeeder::class);

        $this->assertSame(4, AgentChannelBinding::query()->count());

        $this->assertTrue($this->hasRow(
            'sales',
            AgentChannel::Webchat,
            BindingMode::Auto,
            BindingAudience::All,
            OutsideHoursPolicy::Answer,
        ));
        $this->assertTrue($this->hasRow(
            'sales',
            AgentChannel::Sms,
            BindingMode::Draft,
            BindingAudience::KnownContacts,
            OutsideHoursPolicy::Inbox,
        ));
        $this->assertTrue($this->hasRow(
            'sales',
            AgentChannel::Whatsapp,
            BindingMode::Draft,
            BindingAudience::KnownContacts,
            OutsideHoursPolicy::Inbox,
        ));
        $this->assertTrue($this->hasRow(
            'support',
            AgentChannel::Email,
            BindingMode::Draft,
            BindingAudience::ExistingTenants,
            OutsideHoursPolicy::Inbox,
        ));
    }

    #[Test]
    public function rerun_is_idempotent(): void
    {
        $this->seed(AiAgentSeeder::class);
        $before = AgentChannelBinding::query()
            ->orderBy('channel')
            ->get(['ai_agent_id', 'channel', 'site_id', 'mode', 'audience', 'outside_hours'])
            ->toArray();

        $this->seed(AiAgentSeeder::class);

        $this->assertSame(4, AgentChannelBinding::query()->count());
        $this->assertSame(
            $before,
            AgentChannelBinding::query()
                ->orderBy('channel')
                ->get(['ai_agent_id', 'channel', 'site_id', 'mode', 'audience', 'outside_hours'])
                ->toArray(),
        );
    }

    private function hasRow(
        string $agentKey,
        AgentChannel $channel,
        BindingMode $mode,
        BindingAudience $audience,
        OutsideHoursPolicy $outsideHours,
    ): bool {
        return AgentChannelBinding::query()
            ->whereHas('agent', static fn ($query) => $query->where('key', $agentKey))
            ->where('channel', $channel)
            ->whereNull('site_id')
            ->where('mode', $mode)
            ->where('audience', $audience)
            ->where('outside_hours', $outsideHours)
            ->whereNull('archived_at')
            ->exists();
    }
}
