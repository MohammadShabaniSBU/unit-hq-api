<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Ai\Enums\PendingActionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentPendingActionConstraintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function valid_pending_row_inserts(): void
    {
        $action = AgentPendingAction::factory()->create();

        $this->assertTrue($action->exists);
        $this->assertSame(PendingActionStatus::Pending, $action->status);
        $this->assertNull($action->resolved_at);
        $this->assertNotNull($action->site_id);
    }

    #[Test]
    public function pending_with_resolved_at_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->validAttributes([
            'resolved_at' => now(),
        ]);
    }

    #[Test]
    public function approved_without_employee_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->validAttributes([
            'status' => PendingActionStatus::Approved->value,
            'resolved_at' => now(),
            'resolved_by_employee_id' => null,
            'result_id' => 1,
            'result_type' => 'reservation',
        ]);
    }

    #[Test]
    public function approved_without_result_or_failure_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->validAttributes([
            'status' => PendingActionStatus::Approved->value,
            'resolved_at' => now(),
            'resolved_by_employee_id' => Employee::factory()->create()->id,
            'result_id' => null,
            'failure_reason' => null,
        ]);
    }

    #[Test]
    public function rejected_without_employee_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->validAttributes([
            'status' => PendingActionStatus::Rejected->value,
            'resolved_at' => now(),
            'resolved_by_employee_id' => null,
        ]);
    }

    #[Test]
    public function invalid_status_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $site = Site::factory()->create();
        $conversation = AgentConversation::factory()->create(['site_id' => $site->id]);
        $invocation = AgentToolInvocation::factory()->create([
            'agent_conversation_id' => $conversation->id,
        ]);

        DB::table('agent_pending_actions')->insert([
            'agent_conversation_id' => $conversation->id,
            'agent_tool_invocation_id' => $invocation->id,
            'ai_agent_id' => $conversation->ai_agent_id,
            'site_id' => $site->id,
            'tool_key' => 'test.propose',
            'payload' => json_encode(['site_id' => $site->id]),
            'status' => 'done',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function insert_without_site_id_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $site = Site::factory()->create();
        $conversation = AgentConversation::factory()->create(['site_id' => $site->id]);
        $invocation = AgentToolInvocation::factory()->create([
            'agent_conversation_id' => $conversation->id,
        ]);

        AgentPendingAction::query()->create([
            'agent_conversation_id' => $conversation->id,
            'agent_tool_invocation_id' => $invocation->id,
            'ai_agent_id' => $conversation->ai_agent_id,
            'site_id' => null,
            'tool_key' => 'test.propose',
            'payload' => [],
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validAttributes(array $overrides): AgentPendingAction
    {
        $site = Site::factory()->create();
        $conversation = AgentConversation::factory()->create(['site_id' => $site->id]);
        $invocation = AgentToolInvocation::factory()->create([
            'agent_conversation_id' => $conversation->id,
        ]);

        return AgentPendingAction::query()->create(array_merge([
            'agent_conversation_id' => $conversation->id,
            'agent_tool_invocation_id' => $invocation->id,
            'ai_agent_id' => $conversation->ai_agent_id,
            'site_id' => $site->id,
            'tool_key' => 'test.propose',
            'payload' => ['site_id' => $site->id],
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addHour(),
        ], $overrides));
    }
}
