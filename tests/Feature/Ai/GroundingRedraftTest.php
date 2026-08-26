<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\HandoffReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroundingRedraftTest extends TestCase
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
    public function single_ungrounded_date_redrafts_without_handoff(): void
    {
        $conversation = $this->salesConversation();

        $this->driver
            ->enqueueText('Hold until 2099-01-01.')
            ->enqueueText('Could you tell me the exact date you have in mind?');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Please hold a unit.',
        );

        $this->assertNull($turn->handoff);
        $this->assertNull($turn->blockedBy);
        $this->assertSame(2, $this->driver->callCount);
        $this->assertStringContainsString('exact date', $turn->draft);
        $this->assertStringNotContainsString('2099-01-01', $turn->draft);

        $deny = false;
        foreach ($turn->guardrailEvents as $event) {
            if (($event['guard'] ?? null) === 'grounding'
                && ($event['verdict'] ?? null) === 'deny'
                && ($event['detail']['redraft'] ?? false) === true
            ) {
                $deny = true;
                break;
            }
        }
        $this->assertTrue($deny, json_encode($turn->guardrailEvents));
    }

    #[Test]
    public function exhausted_date_redrafts_block_with_grounding_failure(): void
    {
        $conversation = $this->salesConversation();
        $this->driver
            ->enqueueText('Hold until 2099-01-01.')
            ->enqueueText('Hold until 2099-01-01.')
            ->enqueueText('Hold until 2099-01-01.');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Please hold a unit.',
        );

        $max = (int) config('agents.channel.sms.max_redraft_attempts');
        $this->assertSame(1 + $max, $this->driver->callCount);
        $this->assertSame('grounding', $turn->blockedBy);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::GroundingFailure, $turn->handoff->reason);
    }

    private function salesConversation(): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => 'sales',
            'name' => 'sales',
            'is_active' => true,
        ]);

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'locale' => 'en',
        ]);
    }
}
