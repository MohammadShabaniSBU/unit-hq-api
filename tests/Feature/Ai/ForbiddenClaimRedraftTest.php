<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Guards\CannedReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForbiddenClaimRedraftTest extends TestCase
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
    public function unlicensed_reservation_claim_redrafts_without_handoff(): void
    {
        $conversation = $this->salesConversation();
        $alternative = CannedReply::licensedAlternative(
            ForbiddenClaimKey::AvailabilityGuarantee,
            'en',
        );

        $this->driver
            ->enqueueText("I'll move forward with a reservation for next Monday.")
            ->enqueueText($alternative);

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Please hold a unit.',
        );

        $this->assertNull($turn->handoff);
        $this->assertNull($turn->blockedBy);
        $this->assertSame(2, $this->driver->callCount);
        $this->assertStringContainsString('subject to colleague confirmation', $turn->draft);

        $deny = false;
        foreach ($turn->guardrailEvents as $event) {
            if (($event['guard'] ?? null) === 'forbidden_claim' && ($event['verdict'] ?? null) === 'deny') {
                $deny = true;
                break;
            }
        }
        $this->assertTrue($deny, json_encode($turn->guardrailEvents));
    }

    #[Test]
    public function fee_waiver_still_blocks_and_hands_off(): void
    {
        $conversation = $this->salesConversation();

        $this->driver->enqueueText("I've waived your fee.");

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Can you waive the late fee?',
        );

        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame('forbidden_claim', $turn->blockedBy);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::UnsupportedIntent, $turn->handoff->reason);
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
