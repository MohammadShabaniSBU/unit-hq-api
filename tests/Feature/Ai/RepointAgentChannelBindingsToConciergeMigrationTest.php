<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RepointAgentChannelBindingsToConciergeMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repoints_live_bindings_and_writes_one_activity_row_each(): void
    {
        $concierge = AiAgent::factory()->create(['key' => 'concierge', 'name' => 'Customer Agent']);
        $sales = AiAgent::factory()->create(['key' => 'sales-from', 'name' => 'Sales']);
        $support = AiAgent::factory()->create(['key' => 'support-from', 'name' => 'Support']);

        $this->binding($sales, AgentChannel::Webchat, BindingMode::Auto, BindingAudience::All, OutsideHoursPolicy::Answer);
        $this->binding($sales, AgentChannel::Sms, BindingMode::Draft, BindingAudience::KnownContacts, OutsideHoursPolicy::Inbox);
        $this->binding($sales, AgentChannel::Whatsapp, BindingMode::Draft, BindingAudience::KnownContacts, OutsideHoursPolicy::Inbox);
        $this->binding($support, AgentChannel::Email, BindingMode::Draft, BindingAudience::ExistingTenants, OutsideHoursPolicy::Inbox);

        $this->migration()->up();

        $this->assertSame(
            4,
            AgentChannelBinding::query()->live()->where('ai_agent_id', $concierge->id)->count(),
        );
        $this->assertSame(0, AgentChannelBinding::query()->live()->where('ai_agent_id', '!=', $concierge->id)->count());

        $activities = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'ai.binding.updated')
            ->get();
        $this->assertCount(4, $activities);
        foreach ($activities as $activity) {
            $this->assertSame('concierge', $activity->properties->get('to_agent_key'));
            $this->assertContains($activity->properties->get('from_agent_key'), ['sales-from', 'support-from']);
            $this->assertNull($activity->causer_id);
        }
        $this->assertSame(
            1,
            $activities->filter(fn (Activity $row): bool => $row->properties->get('from_agent_key') === 'support-from')->count(),
        );
    }

    #[Test]
    public function second_up_is_a_noop(): void
    {
        $concierge = AiAgent::factory()->create(['key' => 'concierge', 'name' => 'Customer Agent']);
        $sales = AiAgent::factory()->create(['key' => 'sales-from', 'name' => 'Sales']);
        $this->binding($sales, AgentChannel::Sms, BindingMode::Draft, BindingAudience::KnownContacts, OutsideHoursPolicy::Inbox);

        $this->migration()->up();
        $this->assertSame(1, Activity::query()->where('description', 'ai.binding.updated')->count());
        $this->assertSame($concierge->id, AgentChannelBinding::query()->live()->value('ai_agent_id'));

        $this->migration()->up();

        $this->assertSame(1, Activity::query()->where('description', 'ai.binding.updated')->count());
        $this->assertSame(1, AgentChannelBinding::query()->live()->where('ai_agent_id', $concierge->id)->count());
    }

    #[Test]
    public function duplicate_channel_site_pair_throws(): void
    {
        AiAgent::factory()->create(['key' => 'concierge', 'name' => 'Customer Agent']);
        $sales = AiAgent::factory()->create(['key' => 'sales-from', 'name' => 'Sales']);
        $this->binding($sales, AgentChannel::Sms, BindingMode::Draft, BindingAudience::KnownContacts, OutsideHoursPolicy::Inbox);

        DB::statement('DROP INDEX IF EXISTS agent_channel_bindings_channel_site_idx');
        $this->binding($sales, AgentChannel::Sms, BindingMode::Draft, BindingAudience::KnownContacts, OutsideHoursPolicy::Inbox);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate live (channel, site) pair');

        $this->migration()->up();
    }

    #[Test]
    public function live_bindings_without_concierge_row_throw_naming_s27_03(): void
    {
        $sales = AiAgent::factory()->create(['key' => 'sales-from', 'name' => 'Sales']);
        $this->binding($sales, AgentChannel::Sms, BindingMode::Draft, BindingAudience::KnownContacts, OutsideHoursPolicy::Inbox);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('S27-03');

        $this->migration()->up();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_01_000200_repoint_agent_channel_bindings_to_concierge.php');
    }

    private function binding(
        AiAgent $agent,
        AgentChannel $channel,
        BindingMode $mode,
        BindingAudience $audience,
        OutsideHoursPolicy $outsideHours,
    ): AgentChannelBinding {
        return AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => $channel,
            'site_id' => null,
            'mode' => $mode,
            'audience' => $audience,
            'outside_hours' => $outsideHours,
            'archived_at' => null,
        ]);
    }
}
