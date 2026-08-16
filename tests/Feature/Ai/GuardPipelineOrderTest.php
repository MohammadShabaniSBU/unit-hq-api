<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiAgent;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuardPipelineOrderTest extends TestCase
{
    use RefreshDatabase;

    private FakeModelDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);
    }

    #[Test]
    public function pre_model_auction_handoff_never_calls_the_driver(): void
    {
        $conversation = $this->conversation();

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'I got a letter about an auction',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(HandoffReason::LegalOrComplaint, $turn->handoff?->reason);
    }

    #[Test]
    public function loop_turn_limit_beats_handoff_rules(): void
    {
        $conversation = $this->conversation();
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
            'I got a letter about an auction',
        );

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(HandoffReason::TurnLimit, $turn->handoff?->reason);
    }

    #[Test]
    public function duplicate_draft_beats_grounding(): void
    {
        $conversation = $this->conversation();
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::Assistant,
            'content' => 'Thanks, the total is €12.00.',
        ]);

        $this->driver->enqueueText('Thanks, the total is €12.00.');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'hello again',
        );

        $this->assertSame('duplicate_draft', $turn->blockedBy);
        $this->assertSame(HandoffReason::RepeatedFailure, $turn->handoff?->reason);
    }

    #[Test]
    public function grounding_beats_forbidden_claim(): void
    {
        $conversation = $this->conversation();
        $this->driver->enqueueText("I've waived the fee of €999.00.");

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'can you help with the fee',
        );

        $this->assertSame('grounding', $turn->blockedBy);
        $this->assertSame(HandoffReason::GroundingFailure, $turn->handoff?->reason);
    }

    private function conversation(): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => 'support',
            'name' => 'support',
            'is_active' => true,
        ]);

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'locale' => 'en',
        ]);
    }
}
