<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeSessionLifecycleTest extends TestCase
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
        $this->bindVoice($this->site, BindingMode::Auto, BindingAudience::All);
    }

    #[Test]
    public function open_creates_a_session_and_conversation(): void
    {
        $response = $this->postOpen('vb-session-1', '+34911000001')->assertOk();

        $session = VoiceSession::query()->firstOrFail();
        $this->assertSame($session->id, $response->json('id'));
        $this->assertSame('vb-session-1', $response->json('bridge_session_id'));
        $this->assertSame($this->token->id, $session->voice_bridge_token_id);
        $this->assertSame($this->site->id, $session->site_id);
        $this->assertNull($session->ended_at);
        $this->assertNull($session->end_reason);

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame($session->agent_conversation_id, $conversation->id);
        $this->assertSame(AgentOrigin::Voice, $conversation->origin);
        $this->assertSame(AgentAudience::Customer, $conversation->audience);
        $this->assertSame(AgentChannel::Voice, $conversation->channel);
        $this->assertSame($this->site->id, $conversation->site_id);
    }

    #[Test]
    public function open_is_idempotent_on_the_same_bridge_session_id(): void
    {
        $first = $this->postOpen('vb-session-1')->assertOk();
        $second = $this->postOpen('vb-session-1')->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame(1, AgentConversation::query()->count());
    }

    #[Test]
    public function open_then_delegation_reuses_the_opened_session(): void
    {
        $opened = $this->postOpen('vb-session-1')->assertOk();
        $this->enqueueSafeReply();

        $this->postBridge(['session_id' => 'vb-session-1', 'turn_id' => 'turn-1'])->assertOk();

        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame(1, AgentConversation::query()->count());
        $this->assertSame($opened->json('id'), VoiceSession::query()->value('id'));
        $this->assertSame(1, VoiceSessionTurn::query()->count());
    }

    #[Test]
    public function audience_gate_on_open_creates_no_session_and_end_fallback_still_rows_it(): void
    {
        $this->bindVoice($this->site, BindingMode::Auto, BindingAudience::KnownContacts);

        $this->postOpen('vb-declined-1', '+34911000999')
            ->assertOk()
            ->assertJsonPath('id', null)
            ->assertJsonPath('bridge_session_id', 'vb-declined-1');

        $this->assertSame(0, VoiceSession::query()->count());
        $this->assertSame(0, AgentConversation::query()->count());

        $this->postEnd('vb-declined-1', 'caller_hangup')->assertOk();

        $session = VoiceSession::query()->firstOrFail();
        $this->assertSame('vb-declined-1', $session->bridge_session_id);
        $this->assertSame('caller_hangup', $session->end_reason);
        $this->assertNotNull($session->ended_at);
        $this->assertTrue($session->started_at->equalTo($session->ended_at));
        $this->assertTrue(SystemEvent::query()->where('event', 'voice_session.end_without_open')->exists());
    }

    #[Test]
    public function cross_token_collision_is_rejected_from_open_and_from_delegation(): void
    {
        $this->postOpen('shared-call-sid')->assertOk();
        $ownerSessionId = VoiceSession::query()->value('id');

        $otherSite = Site::factory()->create();
        $otherToken = VoiceBridgeToken::factory()->create([
            'site_id' => $otherSite->id,
            'secret' => 'other-bridge-secret-value',
            'secret_previous' => null,
        ]);
        $this->bindVoice($otherSite, BindingMode::Auto, BindingAudience::All);
        RateLimiter::clear('voice-bridge|'.$otherToken->id);

        $this->withHeaders(['X-Voice-Bridge-Secret' => 'other-bridge-secret-value'])
            ->postJson('/api/voice/bridge/'.$otherToken->token.'/session', [
                'bridge_session_id' => 'shared-call-sid',
                'caller_number' => '+34911000002',
            ])
            ->assertNotFound();

        $this->enqueueSafeReply();

        $this->withHeaders(['X-Voice-Bridge-Secret' => 'other-bridge-secret-value'])
            ->postJson('/api/voice/bridge/'.$otherToken->token, [
                'query' => 'Do you have a small unit near the centre?',
                'turn_id' => 'turn-cross',
                'session_id' => 'shared-call-sid',
            ])
            ->assertNotFound();

        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame($ownerSessionId, VoiceSession::query()->value('id'));
        $this->assertSame(0, VoiceSessionTurn::query()->count());
        $this->assertSame(0, $this->driver->callCount);
        $this->assertTrue(SystemEvent::query()->where('event', 'voice_session.cross_token')->exists());
    }

    #[Test]
    public function end_sets_ended_at_and_end_reason(): void
    {
        $this->postOpen('vb-session-end')->assertOk();

        $this->postEnd('vb-session-end', 'idle_timeout')->assertOk();

        $session = VoiceSession::query()->firstOrFail();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('idle_timeout', $session->end_reason);
    }

    #[Test]
    public function end_is_idempotent_and_does_not_overwrite_the_first_reason(): void
    {
        $this->postOpen('vb-session-end')->assertOk();
        $this->postEnd('vb-session-end', 'idle_timeout')->assertOk();

        $session = VoiceSession::query()->firstOrFail();
        $firstEndedAt = $session->ended_at;

        $this->travel(5)->seconds();
        $this->postEnd('vb-session-end', 'caller_hangup')->assertOk();

        $session->refresh();
        $this->assertSame('idle_timeout', $session->end_reason);
        $this->assertTrue($session->ended_at->equalTo($firstEndedAt));
    }

    #[Test]
    public function end_without_open_creates_a_row_and_logs_the_fallback(): void
    {
        $this->postEnd('vb-never-opened', 'caller_hangup')->assertOk();

        $session = VoiceSession::query()->firstOrFail();
        $this->assertSame('vb-never-opened', $session->bridge_session_id);
        $this->assertSame('caller_hangup', $session->end_reason);
        $this->assertTrue($session->started_at->equalTo($session->ended_at));
        $this->assertTrue(SystemEvent::query()->where('event', 'voice_session.end_without_open')->exists());
    }

    #[Test]
    public function unknown_token_is_401_on_open_and_end(): void
    {
        $this->postJson('/api/voice/bridge/not-a-real-token/session', [
            'bridge_session_id' => 'vb-session-1',
        ], ['X-Voice-Bridge-Secret' => $this->secret])
            ->assertUnauthorized();

        $this->postJson('/api/voice/bridge/not-a-real-token/session/vb-session-1/end', [
            'end_reason' => 'caller_hangup',
        ], ['X-Voice-Bridge-Secret' => $this->secret])
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postOpen(string $bridgeSessionId, ?string $callerNumber = null, array $overrides = []): TestResponse
    {
        return $this->withHeaders(['X-Voice-Bridge-Secret' => $this->secret])
            ->postJson('/api/voice/bridge/'.$this->token->token.'/session', array_merge([
                'bridge_session_id' => $bridgeSessionId,
                'caller_number' => $callerNumber,
            ], $overrides));
    }

    private function postEnd(string $bridgeSessionId, string $endReason): TestResponse
    {
        return $this->withHeaders(['X-Voice-Bridge-Secret' => $this->secret])
            ->postJson(
                '/api/voice/bridge/'.$this->token->token.'/session/'.$bridgeSessionId.'/end',
                ['end_reason' => $endReason],
            );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBridge(array $overrides = []): TestResponse
    {
        return $this->withHeaders(['X-Voice-Bridge-Secret' => $this->secret])
            ->postJson('/api/voice/bridge/'.$this->token->token, array_merge([
                'query' => 'Do you have a small unit near the centre?',
                'turn_id' => 'turn-1',
                'session_id' => 'vb-session-1',
            ], $overrides));
    }

    private function bindVoice(Site $site, BindingMode $mode, BindingAudience $audience): void
    {
        $existing = AgentChannelBinding::query()
            ->where('channel', AgentChannel::Voice)
            ->where('site_id', $site->id)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'ai_agent_id' => $this->agent->id,
                'mode' => $mode,
                'audience' => $audience,
                'outside_hours' => OutsideHoursPolicy::Answer,
                'archived_at' => null,
            ])->save();

            return;
        }

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $this->agent->id,
            'channel' => AgentChannel::Voice,
            'site_id' => $site->id,
            'mode' => $mode,
            'audience' => $audience,
            'outside_hours' => OutsideHoursPolicy::Answer,
        ]);
    }

    private function enqueueSafeReply(): void
    {
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
    }
}
