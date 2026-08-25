<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentHandoff;
use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Guards\GuardrailPipeline;
use App\Support\Ai\Guards\GuardrailVerdict;
use App\Support\Ai\PendingActionRecorder;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Ai\ProposableSpyTool;
use Tests\Support\Ai\RecordingTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\TestCase;

class AgentRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private FakeModelDriver $driver;

    private RecordingTool $recording;

    private int $ambientTransactionLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ambientTransactionLevel = DB::transactionLevel();
        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);

        $this->recording = new RecordingTool;
        app(ToolRegistry::class)->register($this->recording);
        app(AgentRegistry::class)->register(new TestAgentDefinition);
    }

    #[Test]
    public function happy_path_writes_turn_facts_invocations_and_agent_usage(): void
    {
        $conversation = $this->conversation('test');
        $this->driver
            ->enqueueToolCalls([['name' => 'test.record', 'id' => 'c1', 'arguments' => []]])
            ->enqueueText('The figure is €84,70 (incl. 21% IVA).');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'How much is it?',
        );

        $this->assertTrue($turn->facts->contains('84,70'));
        $this->assertStringContainsString('€84,70', $turn->draft);
        $this->assertStringContainsString((string) config('ai-handoff.disclosure.en'), $turn->draft);
        $this->assertCount(1, $turn->invocations);
        $this->assertSame(ToolInvocationStatus::Ok, $turn->invocations[0]->status);
        $this->assertSame(ConversationState::Active, $turn->state);
        $this->assertNull($turn->handoff);
        $this->assertDriverNotWrappedInRuntimeTransaction();

        $this->assertSame(1, AgentToolInvocation::query()->where('agent_conversation_id', $conversation->id)->count());
        $usages = AiUsageEvent::query()->where('agent_conversation_id', $conversation->id)->get();
        $this->assertGreaterThanOrEqual(1, $usages->count());
        foreach ($usages as $usage) {
            $this->assertSame('agent', $usage->purpose);
            $this->assertSame($conversation->ai_agent_id, $usage->ai_agent_id);
            $this->assertNull($usage->employee_id);
        }
        $this->assertSame(1, (int) $usages->sum('tool_calls'));
        $this->assertNotNull($conversation->fresh()->last_turn_at);
    }

    #[Test]
    public function tool_loop_stops_at_max_tool_calls_per_turn(): void
    {
        $conversation = $this->conversation('test');
        $max = (int) config('agents.max_tool_calls_per_turn');

        for ($i = 0; $i < $max + 3; $i++) {
            $this->driver->enqueueToolCalls([[
                'name' => 'test.record',
                'id' => 'c'.$i,
                'arguments' => [],
            ]]);
        }

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'loop please',
        );

        $this->assertSame($max, $this->driver->callCount);
        $this->assertCount($max, $turn->invocations);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::Error, $turn->handoff->reason);
        $this->assertDriverNotWrappedInRuntimeTransaction();
    }

    #[Test]
    public function repeated_unresolved_site_info_stops_at_the_tool_call_bound(): void
    {
        $agent = AiAgent::factory()->create([
            'key' => 'sales',
            'name' => 'sales',
            'is_active' => true,
        ]);
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'site_id' => null,
        ]);

        $max = (int) config('agents.max_tool_calls_per_turn');
        for ($i = 0; $i < $max + 3; $i++) {
            $this->driver->enqueueToolCalls([[
                'name' => 'facility.site_info',
                'id' => 'c'.$i,
                'arguments' => [],
            ]]);
        }

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'where are you based?',
        );

        $this->assertLessThanOrEqual($max, count($turn->invocations));
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::Error, $turn->handoff->reason);
        $this->assertNotSame(HandoffReason::TurnLimit, $turn->handoff->reason);
        $this->assertSame('max_tool_calls_per_turn', $turn->handoff->detail['detail'] ?? null);
        $this->assertLessThan(
            (int) config('agents.max_turns'),
            $conversation->messages()->where('role', AgentMessageRole::Assistant)->count(),
        );
        $this->assertDriverNotWrappedInRuntimeTransaction();
    }

    #[Test]
    public function turn_cap_handoffs_turn_limit_without_model_call(): void
    {
        $conversation = $this->conversation('support');
        $max = (int) config('agents.max_turns');

        for ($i = 0; $i < $max; $i++) {
            AgentConversationMessage::query()->create([
                'agent_conversation_id' => $conversation->id,
                'sequence' => $i + 1,
                'role' => AgentMessageRole::Assistant,
                'content' => 'prior',
            ]);
        }

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'one more',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::TurnLimit, $turn->handoff->reason);
        $this->assertSame(HandoffTriggerSource::Rule, $turn->handoff->trigger_source);
        $this->assertSame(ConversationState::AwaitingHuman, $conversation->fresh()->state);
    }

    #[Test]
    public function token_budget_handoffs_budget_exceeded(): void
    {
        $conversation = $this->conversation('support');

        AiUsageEvent::query()->create([
            'call_id' => (string) Str::uuid7(),
            'employee_id' => null,
            'ai_agent_id' => $conversation->ai_agent_id,
            'agent_conversation_id' => $conversation->id,
            'purpose' => 'agent',
            'status' => AiUsageEvent::STATUS_OK,
            'input_tokens' => (int) config('agents.conversation_token_budget'),
            'started_at' => now(),
            'settled_at' => now(),
        ]);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hello',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(HandoffReason::BudgetExceeded, $turn->handoff?->reason);
        $this->assertSame(ConversationState::Closed, $conversation->fresh()->state);
        $this->assertNotNull($conversation->fresh()->closed_at);
    }

    #[Test]
    public function pre_model_handoff_does_not_call_driver(): void
    {
        $conversation = $this->conversation('support');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I will sue you',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertStringContainsString('teammate', $turn->draft);
        $this->assertStringContainsString((string) config('ai-handoff.disclosure.en'), $turn->draft);
        $this->assertSame(HandoffReason::LegalOrComplaint, $turn->handoff?->reason);
        $this->assertSame(HandoffTriggerSource::Rule, $turn->handoff?->trigger_source);
        $this->assertSame(1, AgentHandoff::query()->where('agent_conversation_id', $conversation->id)->count());
    }

    #[Test]
    public function escalate_writes_handoff_from_model(): void
    {
        $conversation = $this->conversation('support');
        $this->driver->enqueueToolCalls([[
            'name' => 'agent.escalate',
            'id' => 'esc1',
            'arguments' => [
                'reason' => HandoffReason::CustomerRequested->value,
                'summary' => 'Asked for a person',
            ],
        ]]);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Can I talk to someone?',
        );

        $this->assertSame(1, $this->driver->callCount);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::CustomerRequested, $turn->handoff->reason);
        $this->assertSame(HandoffTriggerSource::Model, $turn->handoff->trigger_source);
        $this->assertSame(ConversationState::AwaitingHuman, $turn->state);
        $this->assertSame(1, AgentToolInvocation::query()->where('tool_key', 'agent.escalate')->count());
        $this->assertDriverNotWrappedInRuntimeTransaction();
    }

    #[Test]
    public function driver_is_not_called_inside_a_transaction(): void
    {
        $conversation = $this->conversation('support');
        $this->driver->enqueueText('Hello.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hi',
        );

        $this->assertDriverNotWrappedInRuntimeTransaction();
        $this->assertSame(1, $this->driver->callCount);
    }

    #[Test]
    public function guardrail_block_hides_draft_and_writes_handoff(): void
    {
        $this->app->instance(GuardrailPipeline::class, new class implements GuardrailPipeline
        {
            public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
            {
                return GuardrailVerdict::block('grounding', HandoffReason::GroundingFailure);
            }
        });

        $conversation = $this->conversation('support');
        $this->driver->enqueueText('Invented balance €12.00');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'what do I owe?',
        );

        $this->assertSame(
            'I need to hand this to a teammate. '.(string) config('ai-handoff.disclosure.en'),
            $turn->draft,
        );
        $this->assertSame('grounding', $turn->blockedBy);
        $this->assertSame(HandoffReason::GroundingFailure, $turn->handoff?->reason);
        $this->assertSame(HandoffTriggerSource::Guardrail, $turn->handoff?->trigger_source);
        $this->assertTrue(
            AgentConversationMessage::query()
                ->where('agent_conversation_id', $conversation->id)
                ->where('blocked_by', 'grounding')
                ->exists(),
        );
    }

    #[Test]
    public function kill_switch_disables_the_runtime_without_a_model_call(): void
    {
        config(['agents.enabled' => false]);
        $conversation = $this->conversation('support');

        try {
            app(AgentRuntime::class)->turn(
                $conversation,
                $conversation->principal(),
                'hello',
            );
            $this->fail('Expected the kill switch to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Customer-facing agents are disabled.', $e->getMessage());
        }

        $this->assertSame(0, $this->driver->callCount);
    }

    #[Test]
    public function pending_insert_failure_handoffs_error_without_approval_line(): void
    {
        $site = Site::factory()->create();
        $spy = new ProposableSpyTool(siteId: $site->id);
        app(ToolRegistry::class)->register($spy);
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', ['test.spy']));

        $this->app->instance(PendingActionRecorder::class, new class
        {
            public function record(AgentToolInvocation $invocation, ToolResult $result): AgentPendingAction
            {
                throw new RuntimeException('forced insert failure');
            }
        });

        $conversation = $this->conversation('test');
        AgentWritePolicy::factory()->propose()->create([
            'ai_agent_id' => $conversation->ai_agent_id,
            'tool_key' => 'test.spy',
        ]);

        $this->driver->enqueueToolCalls([[
            'name' => 'test.spy',
            'id' => 'c1',
            'arguments' => ['contact_id' => $conversation->contact_id],
        ]]);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please hold a unit',
        );

        $this->assertSame(HandoffReason::Error, $turn->handoff?->reason);
        $this->assertSame(ConversationState::AwaitingHuman, $turn->state);
        $this->assertStringContainsString(CannedReply::Error, $turn->draft);
        $this->assertStringNotContainsString(CannedReply::pendingApproval('en'), $turn->draft);
        $this->assertSame(0, AgentPendingAction::query()->count());
        $this->assertFalse($spy->handleCalled);
    }

    private function conversation(string $agentKey): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => $agentKey,
            'name' => $agentKey,
            'is_active' => true,
        ]);

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
        ]);
    }

    private function assertDriverNotWrappedInRuntimeTransaction(): void
    {
        $this->assertSame(
            $this->ambientTransactionLevel,
            $this->driver->lastTransactionLevel,
            'ModelDriver::stream must not run inside a runtime-opened transaction.',
        );
    }
}
