<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MergeAgentWritePoliciesToConciergeMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function merges_conflicting_pair_with_strictest_wins_and_leaves_legacy_untouched(): void
    {
        $sales = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales Agent']);
        $support = AiAgent::factory()->create(['key' => 'support', 'name' => 'Support Agent']);

        $salesTask = AgentWritePolicy::factory()->create([
            'ai_agent_id' => $sales->id,
            'tool_key' => 'crm.create_task',
            'mode' => WritePolicyMode::Commit,
            'max_per_conversation' => null,
            'max_per_day' => null,
            'min_verification' => null,
        ]);
        $supportTask = AgentWritePolicy::factory()->create([
            'ai_agent_id' => $support->id,
            'tool_key' => 'crm.create_task',
            'mode' => WritePolicyMode::Propose,
            'max_per_conversation' => 5,
            'max_per_day' => 5,
            'min_verification' => VerificationLevel::Verified,
        ]);
        $salesOffer = AgentWritePolicy::factory()->create([
            'ai_agent_id' => $sales->id,
            'tool_key' => 'sales.create_offer',
            'mode' => WritePolicyMode::Commit,
            'max_per_conversation' => 2,
            'max_per_day' => 50,
            'min_verification' => null,
        ]);

        $legacyBefore = AgentWritePolicy::query()
            ->whereIn('id', [$salesTask->id, $supportTask->id, $salesOffer->id])
            ->orderBy('id')
            ->get()
            ->map(fn (AgentWritePolicy $row): array => $row->only([
                'id',
                'ai_agent_id',
                'tool_key',
                'mode',
                'max_per_conversation',
                'max_per_day',
                'min_verification',
            ]))
            ->all();

        $this->migration()->up();

        $concierge = AiAgent::query()->where('key', 'concierge')->firstOrFail();
        $this->assertTrue($concierge->is_active);
        $this->assertNull($concierge->archived_at);

        $mergedTask = AgentWritePolicy::query()
            ->where('ai_agent_id', $concierge->id)
            ->where('tool_key', 'crm.create_task')
            ->firstOrFail();
        $this->assertSame(WritePolicyMode::Propose, $mergedTask->mode);
        $this->assertSame(5, $mergedTask->max_per_conversation);
        $this->assertSame(5, $mergedTask->max_per_day);
        $this->assertSame(VerificationLevel::Verified, $mergedTask->min_verification);
        $this->assertNull($mergedTask->updated_by_employee_id);

        $mergedOffer = AgentWritePolicy::query()
            ->where('ai_agent_id', $concierge->id)
            ->where('tool_key', 'sales.create_offer')
            ->firstOrFail();
        $this->assertSame(WritePolicyMode::Commit, $mergedOffer->mode);
        $this->assertSame(2, $mergedOffer->max_per_conversation);
        $this->assertSame(50, $mergedOffer->max_per_day);

        $this->assertSame(
            $legacyBefore,
            AgentWritePolicy::query()
                ->whereIn('id', [$salesTask->id, $supportTask->id, $salesOffer->id])
                ->orderBy('id')
                ->get()
                ->map(fn (AgentWritePolicy $row): array => $row->only([
                    'id',
                    'ai_agent_id',
                    'tool_key',
                    'mode',
                    'max_per_conversation',
                    'max_per_day',
                    'min_verification',
                ]))
                ->all(),
        );

        $activities = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'ai.write_policy.merged')
            ->get();
        $this->assertCount(2, $activities);

        $taskActivity = $activities->first(
            fn (Activity $row): bool => $row->properties->get('tool_key') === 'crm.create_task',
        );
        $this->assertNotNull($taskActivity);
        $this->assertNull($taskActivity->causer_id);
        $this->assertSame($mergedTask->id, $taskActivity->subject_id);
        $from = $taskActivity->properties->get('from');
        $this->assertCount(2, $from);
        $this->assertSame('propose', $taskActivity->properties->get('to')['mode']);
        $this->assertSame(5, $taskActivity->properties->get('to')['max_per_conversation']);
        $this->assertSame('verified', $taskActivity->properties->get('to')['min_verification']);

        $offerActivity = $activities->first(
            fn (Activity $row): bool => $row->properties->get('tool_key') === 'sales.create_offer',
        );
        $this->assertNotNull($offerActivity);
        $this->assertCount(1, $offerActivity->properties->get('from'));
        $this->assertSame('sales', $offerActivity->properties->get('from')[0]['agent_key']);
    }

    #[Test]
    public function second_up_is_a_noop(): void
    {
        $sales = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales Agent']);
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $sales->id,
            'tool_key' => 'sales.create_reservation',
            'mode' => WritePolicyMode::Propose,
            'max_per_conversation' => 1,
            'max_per_day' => 20,
        ]);

        $this->migration()->up();
        $this->assertSame(1, Activity::query()->where('description', 'ai.write_policy.merged')->count());
        $this->assertSame(1, AgentWritePolicy::query()->where('ai_agent_id', '!=', $sales->id)->count());

        $this->migration()->up();

        $this->assertSame(1, Activity::query()->where('description', 'ai.write_policy.merged')->count());
        $this->assertSame(1, AgentWritePolicy::query()->where('ai_agent_id', '!=', $sales->id)->count());
    }

    #[Test]
    public function empty_database_is_a_noop(): void
    {
        $this->assertSame(0, AiAgent::query()->count());

        $this->migration()->up();

        $this->assertSame(0, AiAgent::query()->count());
        $this->assertSame(0, AgentWritePolicy::query()->count());
        $this->assertSame(0, Activity::query()->where('description', 'ai.write_policy.merged')->count());
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_01_000100_merge_agent_write_policies_to_concierge.php');
    }
}
