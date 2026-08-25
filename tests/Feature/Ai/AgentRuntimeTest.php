<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentGuardrailEvent;
use App\Models\AgentHandoff;
use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Models\Contact;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\AiUsageCost;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Guards\GuardrailPipeline;
use App\Support\Ai\Guards\GuardrailVerdict;
use App\Support\Ai\PendingActionRecorder;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolError;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Ai\ProposableSpyTool;
use Tests\Support\Ai\RecordingTool;
use Tests\Support\Ai\RefEmittingTool;
use Tests\Support\Ai\ScriptedTool;
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
        foreach ($usages as $usage) {
            $this->assertSame('anthropic', $usage->provider);
            $this->assertSame($conversation->aiAgent->model, $usage->model);
            $this->assertNotNull($usage->seq);
            $this->assertNotNull($usage->prompt_version);
            $this->assertNotNull($usage->agent_conversation_message_id);
            $cost = AiUsageCost::forEvent($usage);
            $this->assertNotNull($cost);
            $this->assertSame('USD', $cost['currency']);
            $this->assertNotSame('', $cost['estimated_cost']);
        }
        $this->assertGreaterThan(0, AgentGuardrailEvent::query()->where('agent_conversation_id', $conversation->id)->count());
        AgentGuardrailEvent::query()->where('agent_conversation_id', $conversation->id)->each(function (AgentGuardrailEvent $event): void {
            $this->assertNotNull($event->agent_conversation_message_id);
            $this->assertNotNull($event->seq);
        });
    }

    #[Test]
    public function tool_role_messages_carry_display_not_structured_data(): void
    {
        $conversation = $this->conversation('test');
        $this->driver
            ->enqueueToolCalls([['name' => 'test.record', 'id' => 'c1', 'arguments' => []]])
            ->enqueueText('The figure is €84,70 (incl. 21% IVA).');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'How much is it?',
        );

        $toolMessages = array_values(array_filter(
            $this->driver->lastMessages,
            fn (array $message): bool => ($message['role'] ?? null) === 'tool',
        ));
        $this->assertNotEmpty($toolMessages);
        foreach ($toolMessages as $message) {
            $content = (string) $message['content'];
            $this->assertStringContainsString('€84,70 (incl. 21% IVA)', $content);
            $this->assertStringNotContainsString('unit_class_id', $content);
            $this->assertStringNotContainsString('"classes"', $content);
            $this->assertStringNotContainsString('"amount"', $content);
            $this->assertStringNotContainsString('84.70', $content);
        }
    }

    #[Test]
    public function in_turn_tool_message_carries_the_refs_line(): void
    {
        $conversation = $this->refsConversation();
        $this->driver
            ->enqueueToolCalls([['name' => 'test.refs', 'id' => 'c1', 'arguments' => []]])
            ->enqueueText('Here is what we have.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'what is available?',
        );

        $toolMessages = $this->toolMessagesSentToModel();
        $this->assertCount(1, $toolMessages);
        $this->assertStringContainsString(
            'Refs: site 1 = Madrid Centro; unit_class 12 = Trastero 16 m² XL',
            $toolMessages[0],
        );
    }

    #[Test]
    public function persisted_tool_message_and_result_summary_omit_the_refs_line(): void
    {
        $conversation = $this->refsConversation();
        $this->driver
            ->enqueueToolCalls([['name' => 'test.refs', 'id' => 'c1', 'arguments' => []]])
            ->enqueueText('Here is what we have.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'what is available?',
        );

        $toolRow = AgentConversationMessage::query()
            ->where('agent_conversation_id', $conversation->id)
            ->where('role', AgentMessageRole::Tool)
            ->firstOrFail();
        $this->assertStringNotContainsString('Refs:', (string) $toolRow->content);
        $this->assertSame('c1', $toolRow->tool_call_id);

        $invocation = AgentToolInvocation::query()
            ->where('agent_conversation_id', $conversation->id)
            ->firstOrFail();
        $this->assertStringNotContainsString('Refs:', (string) $invocation->result_summary);
        $this->assertSame('c1', $invocation->tool_call_id);
    }

    #[Test]
    public function prior_turn_tool_messages_rehydrate_with_their_refs_line(): void
    {
        $conversation = $this->refsConversation();
        $this->seedToolTurn($conversation, 'call_1', 'Three units available at Madrid Centro.', [
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m² XL'),
        ]);

        $this->driver->enqueueText('Let me check the price.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'how much for the 16?',
        );

        $toolMessages = $this->toolMessagesSentToModel();
        $this->assertCount(1, $toolMessages);
        $this->assertStringContainsString('Three units available at Madrid Centro.', $toolMessages[0]);
        $this->assertStringContainsString(
            'Refs: site 1 = Madrid Centro; unit_class 12 = Trastero 16 m² XL',
            $toolMessages[0],
        );
    }

    #[Test]
    public function historical_invocation_without_a_tool_call_id_rehydrates_without_refs(): void
    {
        $conversation = $this->refsConversation();
        $this->seedToolTurn(
            $conversation,
            'call_1',
            'Three units available at Madrid Centro.',
            [EntityRef::of(EntityType::Site, 1, 'Madrid Centro')],
            linkInvocation: false,
        );

        $this->driver->enqueueText('Let me check the price.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'how much for the 16?',
        );

        $toolMessages = $this->toolMessagesSentToModel();
        $this->assertCount(1, $toolMessages);
        $this->assertStringContainsString('Three units available at Madrid Centro.', $toolMessages[0]);
        $this->assertStringNotContainsString('Refs:', $toolMessages[0]);
    }

    #[Test]
    public function turns_reusing_one_tool_call_id_rehydrate_their_own_refs(): void
    {
        $conversation = $this->refsConversation();
        $this->seedToolTurn($conversation, 'call_1', 'Three units available at Madrid Centro.', [
            EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m² XL'),
        ]);
        $this->seedToolTurn($conversation, 'call_1', 'One unit available at Madrid Norte.', [
            EntityRef::of(EntityType::Site, 4, 'Madrid Norte'),
            EntityRef::of(EntityType::UnitClass, 8, 'Trastero 12 m²'),
        ]);

        $this->driver->enqueueText('Let me check the price.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'how much for either?',
        );

        $toolMessages = $this->toolMessagesSentToModel();
        $this->assertCount(2, $toolMessages);

        $this->assertStringContainsString('Three units available at Madrid Centro.', $toolMessages[0]);
        $this->assertStringContainsString(
            'Refs: site 1 = Madrid Centro; unit_class 12 = Trastero 16 m² XL',
            $toolMessages[0],
        );
        $this->assertStringNotContainsString('Madrid Norte', $toolMessages[0]);

        $this->assertStringContainsString('One unit available at Madrid Norte.', $toolMessages[1]);
        $this->assertStringContainsString(
            'Refs: site 4 = Madrid Norte; unit_class 8 = Trastero 12 m²',
            $toolMessages[1],
        );
        $this->assertStringNotContainsString('Madrid Centro', $toolMessages[1]);
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

        $retries = (int) config('agents.max_tool_retries');
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

        $this->assertCount($retries, $turn->invocations);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::Error, $turn->handoff->reason);
        $this->assertNotSame(HandoffReason::TurnLimit, $turn->handoff->reason);
        $this->assertSame('tool_retry_exhausted', $turn->handoff->detail['detail'] ?? null);
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

    #[Test]
    public function first_invalid_arguments_returns_recovery_line_and_does_not_handoff(): void
    {
        $script = $this->scriptedConversation();
        $script->script = [
            ToolResult::fail(ToolError::invalidArguments('bad args', [
                'tool' => 'test.script',
                'hint' => 'retry with valid arguments',
            ])),
        ];

        $this->driver
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c1', 'arguments' => []]])
            ->enqueueText('Let me try another way.');

        $conversation = $this->conversation('test');
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please run it',
        );

        $this->assertNull($turn->handoff);
        $this->assertSame(0, AgentHandoff::query()->where('agent_conversation_id', $conversation->id)->count());
        $toolMessages = $this->toolMessagesSentToModel();
        $this->assertNotEmpty($toolMessages);
        $this->assertStringContainsString('Recovery: call test.script', $toolMessages[0]);
    }

    #[Test]
    public function second_consecutive_failure_of_the_same_tool_handoffs_retry_exhausted(): void
    {
        $script = $this->scriptedConversation();
        $failure = ToolResult::fail(ToolError::invalidArguments('bad args', [
            'tool' => 'test.script',
            'hint' => 'retry with valid arguments',
        ]));
        $script->script = [$failure, $failure];

        $this->driver
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c1', 'arguments' => []]])
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c2', 'arguments' => []]]);

        $conversation = $this->conversation('test');
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please run it',
        );

        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::Error, $turn->handoff->reason);
        $this->assertSame(HandoffTriggerSource::Rule, $turn->handoff->trigger_source);
        $this->assertSame('tool_retry_exhausted', $turn->handoff->detail['detail'] ?? null);
        $this->assertSame('test.script', $turn->handoff->detail['tool'] ?? null);
        $this->assertSame('invalid_arguments', $turn->handoff->detail['error_code'] ?? null);
        $this->assertSame(1, AgentHandoff::query()->where('agent_conversation_id', $conversation->id)->count());
    }

    #[Test]
    public function failure_then_ok_then_failure_on_the_same_tool_does_not_handoff(): void
    {
        $script = $this->scriptedConversation();
        $failure = ToolResult::fail(ToolError::invalidArguments('bad args', [
            'tool' => 'test.script',
            'hint' => 'retry with valid arguments',
        ]));
        $script->script = [
            $failure,
            ToolResult::ok(['ok' => true], 'it worked', new FactBag),
            $failure,
        ];

        $this->driver
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c1', 'arguments' => []]])
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c2', 'arguments' => []]])
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c3', 'arguments' => []]])
            ->enqueueText('All done.');

        $conversation = $this->conversation('test');
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please run it',
        );

        $this->assertNull($turn->handoff);
        $this->assertSame(0, AgentHandoff::query()->where('agent_conversation_id', $conversation->id)->count());
        $this->assertSame(3, $script->calls);
    }

    #[Test]
    public function error_escalate_with_retry_budget_is_refused_and_does_not_feed_the_counter(): void
    {
        $script = $this->scriptedConversation();
        $failure = ToolResult::fail(ToolError::invalidArguments('bad args', [
            'tool' => 'test.script',
            'hint' => 'retry with valid arguments',
        ]));
        $script->script = [
            $failure,
            ToolResult::ok(['ok' => true], 'retried after escalate refusal', new FactBag),
        ];

        $this->driver
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c1', 'arguments' => []]])
            ->enqueueToolCalls([[
                'name' => 'agent.escalate',
                'id' => 'esc1',
                'arguments' => [
                    'reason' => HandoffReason::Error->value,
                    'summary' => 'The tool failed',
                ],
            ]])
            ->enqueueToolCalls([[
                'name' => 'agent.escalate',
                'id' => 'esc2',
                'arguments' => [
                    'reason' => HandoffReason::Error->value,
                    'summary' => 'Still failing',
                ],
            ]])
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c2', 'arguments' => []]])
            ->enqueueText('I will keep trying.');

        $conversation = $this->conversation('test');
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please run it',
        );

        $this->assertNull($turn->handoff);
        $this->assertSame(0, AgentHandoff::query()->where('agent_conversation_id', $conversation->id)->count());

        $escalations = AgentToolInvocation::query()
            ->where('agent_conversation_id', $conversation->id)
            ->where('tool_key', 'agent.escalate')
            ->get();
        $this->assertCount(2, $escalations);
        foreach ($escalations as $escalation) {
            $this->assertSame(ToolInvocationStatus::Error, $escalation->status);
        }

        $this->assertSame(2, $script->calls);
    }

    #[Test]
    public function error_escalate_with_no_prior_failure_names_no_tool(): void
    {
        $this->scriptedConversation();

        $this->driver->enqueueToolCalls([[
            'name' => 'agent.escalate',
            'id' => 'esc1',
            'arguments' => [
                'reason' => HandoffReason::Error->value,
                'summary' => 'I cannot help',
            ],
        ]])->enqueueText('Let me stay with you.');

        $conversation = $this->conversation('test');
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hello',
        );

        $this->assertNull($turn->handoff);
        $this->assertSame(0, AgentHandoff::query()->where('agent_conversation_id', $conversation->id)->count());
        $toolMessages = $this->toolMessagesSentToModel();
        $this->assertNotEmpty($toolMessages);
        $this->assertStringContainsString(
            'no tool has returned an error in this turn; use a different reason',
            $toolMessages[0],
        );
        $this->assertStringNotContainsString('Recovery: call', $toolMessages[0]);
    }

    #[Test]
    public function customer_requested_escalate_still_handoffs_while_retry_budget_remains(): void
    {
        $script = $this->scriptedConversation();
        $script->script = [
            ToolResult::fail(ToolError::invalidArguments('bad args', [
                'tool' => 'test.script',
                'hint' => 'retry with valid arguments',
            ])),
        ];

        $this->driver
            ->enqueueToolCalls([['name' => 'test.script', 'id' => 'c1', 'arguments' => []]])
            ->enqueueToolCalls([[
                'name' => 'agent.escalate',
                'id' => 'esc1',
                'arguments' => [
                    'reason' => HandoffReason::CustomerRequested->value,
                    'summary' => 'Asked for a person',
                ],
            ]]);

        $conversation = $this->conversation('test');
        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'please run it',
        );

        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::CustomerRequested, $turn->handoff->reason);
        $this->assertSame(HandoffTriggerSource::Model, $turn->handoff->trigger_source);
        $this->assertSame(ToolInvocationStatus::Ok, AgentToolInvocation::query()
            ->where('tool_key', 'agent.escalate')
            ->firstOrFail()
            ->status);
    }

    #[Test]
    public function create_contact_promotes_anonymous_principal_so_reservation_reaches_propose(): void
    {
        $site = Site::factory()->create();
        $spy = new ProposableSpyTool(
            key: 'sales.create_reservation',
            required: VerificationLevel::ChannelAsserted,
            contactKeys: [],
            siteId: $site->id,
        );
        app(ToolRegistry::class)->register($spy);
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', [
            'crm.create_contact',
            'sales.create_reservation',
        ]));

        $conversation = $this->anonymousConversation('test');
        AgentWritePolicy::factory()->propose()->create([
            'ai_agent_id' => $conversation->ai_agent_id,
            'tool_key' => 'sales.create_reservation',
        ]);

        $this->driver
            ->enqueueToolCalls([
                [
                    'name' => 'crm.create_contact',
                    'id' => 'c1',
                    'arguments' => [
                        'first_name' => 'Ada',
                        'last_name' => 'Lovelace',
                        'email' => 'ada-promote@example.com',
                    ],
                ],
                [
                    'name' => 'sales.create_reservation',
                    'id' => 'c2',
                    'arguments' => ['contact_id' => 1],
                ],
            ])
            ->enqueueText('I have opened a hold for review.');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Ada Lovelace, ada-promote@example.com — hold a unit please',
        );

        $this->assertNull($turn->handoff);
        $this->assertTrue($spy->proposeCalled);
        $this->assertFalse($spy->handleCalled);

        $reservation = AgentToolInvocation::query()
            ->where('agent_conversation_id', $conversation->id)
            ->where('tool_key', 'sales.create_reservation')
            ->firstOrFail();
        $this->assertSame(ToolDeniedReason::RequiresApproval, $reservation->denied_reason);
        $this->assertSame(VerificationLevel::ChannelAsserted, $reservation->principal_verification);

        $conversation->refresh();
        $this->assertSame(VerificationLevel::ChannelAsserted, $conversation->verification_level);
        $this->assertNotNull($conversation->contact_id);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Ai->value)
            ->where('description', 'agent.conversation.principal_promoted')
            ->where('subject_id', $conversation->id)
            ->first();
        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('anonymous', $properties['from'] ?? null);
        $this->assertSame('channel_asserted', $properties['to'] ?? null);
        $this->assertSame($conversation->contact_id, $properties['contact_id'] ?? null);
    }

    #[Test]
    public function second_create_contact_in_the_same_conversation_is_invalid_arguments(): void
    {
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', ['crm.create_contact']));
        $conversation = $this->anonymousConversation('test');

        $this->driver
            ->enqueueToolCalls([
                [
                    'name' => 'crm.create_contact',
                    'id' => 'c1',
                    'arguments' => ['first_name' => 'Ada', 'email' => 'ada-once@example.com'],
                ],
                [
                    'name' => 'crm.create_contact',
                    'id' => 'c2',
                    'arguments' => ['first_name' => 'Other', 'email' => 'other@example.com'],
                ],
            ])
            ->enqueueText('I already have your details.');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Ada Lovelace, ada-once@example.com',
        );

        $this->assertNull($turn->handoff);
        $second = AgentToolInvocation::query()
            ->where('agent_conversation_id', $conversation->id)
            ->where('tool_call_id', 'c2')
            ->firstOrFail();
        $this->assertSame(ToolInvocationStatus::Error, $second->status);
        $this->assertSame(ToolErrorCode::InvalidArguments->value, $second->result['error']['code'] ?? null);
        $this->assertSame(1, Contact::query()->count());
    }

    #[Test]
    public function already_channel_asserted_conversation_is_not_re_promoted(): void
    {
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', ['crm.create_contact']));
        $contact = Contact::factory()->create();
        $agent = AiAgent::factory()->create(['key' => 'test', 'name' => 'test', 'is_active' => true]);
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'audience' => AgentAudience::Customer,
            'contact_id' => $contact->id,
            'employee_id' => null,
            'verification_level' => VerificationLevel::ChannelAsserted,
        ]);

        $this->driver
            ->enqueueToolCalls([[
                'name' => 'crm.create_contact',
                'id' => 'c1',
                'arguments' => ['first_name' => 'Ada', 'email' => 'ada-again@example.com'],
            ]])
            ->enqueueText('You are already on file.');

        app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Ada Lovelace, ada-again@example.com',
        );

        $conversation->refresh();
        $this->assertSame(VerificationLevel::ChannelAsserted, $conversation->verification_level);
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame(0, Activity::query()
            ->where('description', 'agent.conversation.principal_promoted')
            ->count());
        $this->assertSame(ToolErrorCode::InvalidArguments->value, AgentToolInvocation::query()
            ->where('tool_key', 'crm.create_contact')
            ->value('result')['error']['code'] ?? null);
    }

    #[Test]
    public function create_contact_dedupe_promotes_onto_matched_contact_but_billing_stays_denied(): void
    {
        $existing = Contact::factory()->create(['email' => 'tenant@example.com']);
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', [
            'crm.create_contact',
            'billing.balance',
        ]));
        $conversation = $this->anonymousConversation('test');

        $this->driver
            ->enqueueToolCalls([
                [
                    'name' => 'crm.create_contact',
                    'id' => 'c1',
                    'arguments' => [
                        'first_name' => 'Tenant',
                        'email' => 'tenant@example.com',
                    ],
                ],
                [
                    'name' => 'billing.balance',
                    'id' => 'c2',
                    'arguments' => [],
                ],
            ])
            ->enqueueText('I cannot see a balance until you verify.');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I am Tenant, tenant@example.com — what do I owe?',
        );

        $this->assertNull($turn->handoff);
        $conversation->refresh();
        $this->assertSame(VerificationLevel::ChannelAsserted, $conversation->verification_level);
        $this->assertSame($existing->id, $conversation->contact_id);
        $this->assertSame(1, Contact::query()->count());

        $balance = AgentToolInvocation::query()
            ->where('agent_conversation_id', $conversation->id)
            ->where('tool_key', 'billing.balance')
            ->firstOrFail();
        $this->assertSame(ToolDeniedReason::Verification, $balance->denied_reason);
        $this->assertSame(VerificationLevel::ChannelAsserted, $balance->principal_verification);
    }

    /**
     * A `test` conversation whose tools are the scripted one plus escalate.
     */
    private function scriptedConversation(): ScriptedTool
    {
        $script = new ScriptedTool;
        app(ToolRegistry::class)->register($script);
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', ['test.script', 'agent.escalate']));

        return $script;
    }

    /**
     * A `test` conversation whose only tool is the entity-emitting one.
     */
    private function refsConversation(): AgentConversation
    {
        app(ToolRegistry::class)->register(new RefEmittingTool);
        app(AgentRegistry::class)->register(new TestAgentDefinition('test', ['test.refs']));

        return $this->conversation('test');
    }

    /**
     * A completed prior turn: user, assistant with one tool call, the tool message
     * storing `display` only, and the invocation carrying the entities.
     *
     * @param  list<EntityRef>  $entities
     */
    private function seedToolTurn(
        AgentConversation $conversation,
        string $callId,
        string $display,
        array $entities,
        bool $linkInvocation = true,
    ): void {
        $sequence = (int) AgentConversationMessage::query()
            ->where('agent_conversation_id', $conversation->id)
            ->max('sequence');

        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => ++$sequence,
            'role' => AgentMessageRole::User,
            'content' => 'what do you have?',
        ]);

        $assistant = AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => ++$sequence,
            'role' => AgentMessageRole::Assistant,
            'content' => null,
            'tool_calls' => [['name' => 'test.refs', 'id' => $callId, 'arguments' => []]],
        ]);

        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => ++$sequence,
            'role' => AgentMessageRole::Tool,
            'content' => $display,
            'tool_call_id' => $callId,
        ]);

        AgentToolInvocation::query()->create([
            'agent_conversation_id' => $conversation->id,
            'agent_conversation_message_id' => $assistant->id,
            'tool_call_id' => $linkInvocation ? $callId : null,
            'tool_key' => 'test.refs',
            'arguments' => [],
            'result' => [
                'entities' => array_map(
                    static fn (EntityRef $ref): array => $ref->toArray(),
                    $entities,
                ),
            ],
            'result_summary' => $display,
            'status' => ToolInvocationStatus::Ok,
        ]);
    }

    /**
     * @return list<string>
     */
    private function toolMessagesSentToModel(): array
    {
        $contents = [];
        foreach ($this->driver->lastMessages as $message) {
            if (($message['role'] ?? null) === 'tool') {
                $contents[] = (string) $message['content'];
            }
        }

        return $contents;
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

    private function anonymousConversation(string $agentKey): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => $agentKey,
            'name' => $agentKey,
            'is_active' => true,
        ]);

        return AgentConversation::factory()->anonymous()->create([
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
