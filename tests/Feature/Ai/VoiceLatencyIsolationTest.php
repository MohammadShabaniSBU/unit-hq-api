<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Http\Controllers\VoiceBridgeController;
use App\Listeners\RespondWithAgent;
use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSessionTurn;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Drivers\ProviderRateLimitedException;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\VoiceBridgeTurn;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceLatencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private FakeModelDriver $driver;

    private VoiceBridgeToken $token;

    private string $secret = 'bridge-secret-value-for-tests';

    private Site $site;

    private AiAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);

        $this->seed(AiAgentSeeder::class);
        $this->agent = AiAgent::query()->where('key', 'concierge')->firstOrFail();
        $this->site = Site::factory()->create();
        $this->token = VoiceBridgeToken::factory()->create([
            'site_id' => $this->site->id,
            'secret' => $this->secret,
            'secret_previous' => null,
        ]);
        RateLimiter::clear('voice-bridge|'.$this->token->id);
        RateLimiter::clear('ai-provider:voice');
        RateLimiter::clear('ai-provider:batch');

        Setting::setGeneral(Setting::general()->with(sendWindowStart: '00:00', sendWindowEnd: null));
        $this->bindVoice();
    }

    #[Test]
    public function zero_voice_budget_returns_handoff_sentence_and_does_not_speak_the_canned_error(): void
    {
        config(['agents.channel.voice.turn_timeout_ms' => 0]);
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $handoff = (string) config('agents.voice.handoff_sentence');

        $this->postBridge(['turn_id' => 'turn-budget-zero'])
            ->assertOk()
            ->assertJsonPath('text', $handoff)
            ->assertJsonPath('transfer', true)
            ->assertJsonPath('destination', 'main_line');

        $this->assertSame(0, $this->driver->callCount);
        $this->assertNotSame(CannedReply::Error, $this->postBridge(['turn_id' => 'turn-budget-zero'])->json('text'));
        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.turn_budget_exceeded')->exists());
        $this->assertTrue(VoiceSessionTurn::query()->where('turn_id', 'turn-budget-zero')->where('budget_exceeded', true)->exists());
        $this->assertSame($handoff, VoiceSessionTurn::query()->where('turn_id', 'turn-budget-zero')->value('answer_text'));
    }

    #[Test]
    public function late_draft_is_persisted_blocked_and_not_spoken(): void
    {
        config(['agents.channel.voice.turn_timeout_ms' => 200]);
        $this->driver->sleepMs = 350;
        $late = 'We have units available. Which size are you looking for?';
        $this->driver->enqueueText($late);
        $handoff = (string) config('agents.voice.handoff_sentence');

        $this->postBridge(['turn_id' => 'turn-late'])
            ->assertOk()
            ->assertJsonPath('text', $handoff)
            ->assertJsonPath('transfer', true);

        $this->assertSame(1, $this->driver->callCount);
        $this->assertNotSame($late, VoiceSessionTurn::query()->where('turn_id', 'turn-late')->value('answer_text'));
        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.turn_budget_exceeded')->exists());

        $blocked = AgentConversationMessage::query()
            ->where('role', 'assistant')
            ->where('blocked_by', 'turn_timeout')
            ->first();
        $this->assertNotNull($blocked);
        $this->assertStringContainsString('units available', (string) $blocked->content);
    }

    #[Test]
    public function voice_redraft_budget_is_one_then_escalates(): void
    {
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Voice,
            'origin' => AgentOrigin::Voice,
            'contact_id' => null,
            'site_id' => $this->site->id,
            'verification_level' => VerificationLevel::Anonymous,
        ]);

        $this->driver
            ->enqueueText('Hold until 2099-01-01.')
            ->enqueueText('Hold until 2099-01-01.')
            ->enqueueText('Hold until 2099-01-01.');

        $turn = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Please hold a unit.',
        );

        $this->assertSame(2, $this->driver->callCount);
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::GroundingFailure, $turn->handoff->reason);
    }

    #[Test]
    public function sms_redraft_budget_is_unchanged(): void
    {
        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Sms,
            'contact_id' => null,
            'verification_level' => VerificationLevel::Anonymous,
        ]);

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
        $this->assertNotNull($turn->handoff);
        $this->assertSame(HandoffReason::GroundingFailure, $turn->handoff->reason);
    }

    #[Test]
    public function exhausted_voice_provider_bucket_handoffs_without_reserving_usage(): void
    {
        config(['agents.provider_rate_per_minute.voice' => 1]);
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $handoff = (string) config('agents.voice.handoff_sentence');

        $this->postBridge(['turn_id' => 'turn-ok'])->assertOk()->assertJsonPath('transfer', false);
        $this->assertSame(1, $this->driver->callCount);
        $usageAfterFirst = AiUsageEvent::query()->count();
        $this->assertGreaterThan(0, $usageAfterFirst);

        $this->postBridge(['turn_id' => 'turn-throttled'])
            ->assertOk()
            ->assertJsonPath('text', $handoff)
            ->assertJsonPath('transfer', true);

        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame($usageAfterFirst, AiUsageEvent::query()->count());
        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.provider_throttled')->exists());
        $this->assertFalse(SystemEvent::query()->where('event', 'ai.voice.turn_budget_exceeded')->exists());
    }

    #[Test]
    public function batch_provider_hits_do_not_consume_the_voice_bucket(): void
    {
        config([
            'agents.provider_rate_per_minute.voice' => 1,
            'agents.provider_rate_per_minute.batch' => 5,
        ]);

        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Sms,
            'contact_id' => null,
            'verification_level' => VerificationLevel::Anonymous,
        ]);
        $this->driver->enqueueText('We can help you find a unit. What size do you need?');
        app(AgentRuntime::class)->turn($conversation, $conversation->principal(), 'Hello');
        $this->assertSame(1, $this->driver->callCount);

        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $this->postBridge(['turn_id' => 'turn-voice-after-batch'])
            ->assertOk()
            ->assertJsonPath('transfer', false);

        $this->assertSame(2, $this->driver->callCount);
    }

    #[Test]
    public function batch_provider_exhaustion_throws_and_reserves_nothing(): void
    {
        config(['agents.provider_rate_per_minute.batch' => 1]);

        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Sms,
            'contact_id' => null,
            'verification_level' => VerificationLevel::Anonymous,
        ]);
        $this->driver->enqueueText('We can help you find a unit. What size do you need?');
        app(AgentRuntime::class)->turn($conversation, $conversation->principal(), 'Hello');
        $usage = AiUsageEvent::query()->count();

        $second = AgentConversation::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Sms,
            'contact_id' => null,
            'verification_level' => VerificationLevel::Anonymous,
        ]);
        $this->driver->enqueueText('We can help you find a unit. What size do you need?');

        try {
            app(AgentRuntime::class)->turn($second, $second->principal(), 'Hello again');
            $this->fail('Expected ProviderRateLimitedException');
        } catch (ProviderRateLimitedException) {
            $this->assertSame($usage, AiUsageEvent::query()->count());
            $this->assertSame(1, $this->driver->callCount);
        }
    }

    #[Test]
    public function voice_bridge_does_not_execute_on_the_ai_queue(): void
    {
        $this->assertFalse(is_subclass_of(VoiceBridgeTurn::class, ShouldQueue::class));
        $this->assertFalse(is_subclass_of(VoiceBridgeController::class, ShouldQueue::class));
        $this->assertSame('ai', app(RespondWithAgent::class)->queue);

        Queue::fake();
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $this->postBridge()->assertOk();
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBridge(array $overrides = []): TestResponse
    {
        return $this->withHeaders([
            'X-Voice-Bridge-Secret' => $this->secret,
        ])->postJson(
            '/api/voice/bridge/'.$this->token->token,
            array_merge([
                'query' => 'Do you have a small unit near the centre?',
                'turn_id' => 'turn-1',
                'session_id' => 'vb-session-1',
            ], $overrides),
        );
    }

    private function bindVoice(): void
    {
        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Voice,
            'site_id' => $this->site->id,
            'mode' => BindingMode::Auto,
            'audience' => BindingAudience::All,
            'outside_hours' => OutsideHoursPolicy::Answer,
        ]);
    }
}
