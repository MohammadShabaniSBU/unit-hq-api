<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContactChannelType;
use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\ContactChannel;
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
use App\Support\Ai\Enums\VerificationLevel;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeEndpointTest extends TestCase
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

        $this->bindVoice(BindingMode::Auto, BindingAudience::All);
    }

    #[Test]
    public function valid_token_and_secret_returns_text_without_entity_ids(): void
    {
        $this->enqueueSafeReply();

        $response = $this->postBridge()->assertOk();

        $this->assertSame(['text', 'transfer'], array_keys($response->json()));
        $this->assertIsString($response->json('text'));
        $this->assertNotSame('', $response->json('text'));
        $this->assertFalse($response->json('transfer'));
        $this->assertStringNotContainsString((string) $this->token->id, (string) $response->json('text'));
        $this->assertDatabaseCount('voice_sessions', 1);
        $this->assertDatabaseCount('agent_conversations', 1);
    }

    #[Test]
    public function secret_previous_is_accepted_during_rotation(): void
    {
        $previous = 'previous-bridge-secret-value';
        $this->token->forceFill(['secret_previous' => $previous])->save();
        $this->enqueueSafeReply();

        $this->postBridge(headers: ['X-Voice-Bridge-Secret' => $previous])
            ->assertOk()
            ->assertJsonPath('transfer', false);
    }

    #[Test]
    public function bad_secret_is_401_and_records_one_auth_failed_event(): void
    {
        $this->postBridge(headers: ['X-Voice-Bridge-Secret' => 'not-the-secret'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('text');

        $events = SystemEvent::query()->where('event', 'ai.voice.bridge_auth_failed')->get();
        $this->assertCount(1, $events);
        $this->assertSame($this->token->id, $events->first()?->subject_id);
        $this->assertSame('bad_secret', $events->first()?->payload['reason'] ?? null);
        $this->assertStringNotContainsString($this->secret, (string) json_encode($events->first()?->payload));
        $this->assertSame(0, $this->driver->callCount);
    }

    #[Test]
    public function unknown_token_is_401_without_an_event_or_handoff_body(): void
    {
        $this->postJson('/api/voice/bridge/not-a-real-token', $this->payload(), [
            'X-Voice-Bridge-Secret' => $this->secret,
        ])
            ->assertUnauthorized()
            ->assertJsonMissingPath('text');

        $this->assertSame(0, SystemEvent::query()->where('event', 'ai.voice.bridge_auth_failed')->count());
        $this->assertSame(0, $this->driver->callCount);
    }

    #[Test]
    public function revoked_token_is_401_without_an_event(): void
    {
        $this->token->forceFill(['revoked_at' => now()])->save();

        $this->postBridge()
            ->assertUnauthorized()
            ->assertJsonMissingPath('text');

        $this->assertSame(0, SystemEvent::query()->where('event', 'ai.voice.bridge_auth_failed')->count());
    }

    #[Test]
    public function first_delegation_creates_session_and_conversation_second_reuses_both(): void
    {
        $this->enqueueSafeReply();
        $this->enqueueSafeReply();

        $this->postBridge(['session_id' => 'vb-session-1', 'turn_id' => 'turn-1'])->assertOk();
        $this->postBridge(['session_id' => 'vb-session-1', 'turn_id' => 'turn-2'])->assertOk();

        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame(1, AgentConversation::query()->count());

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame(AgentOrigin::Voice, $conversation->origin);
        $this->assertSame(AgentAudience::Customer, $conversation->audience);
        $this->assertSame(AgentChannel::Voice, $conversation->channel);
        $this->assertSame($this->site->id, $conversation->site_id);
        $this->assertNull($conversation->created_by_employee_id);
        $this->assertSame(VerificationLevel::Anonymous, $conversation->verification_level);
    }

    #[Test]
    public function camel_case_session_and_turn_ids_are_accepted(): void
    {
        $this->enqueueSafeReply();

        $this->withHeaders(['X-Voice-Bridge-Secret' => $this->secret])
            ->postJson('/api/voice/bridge/'.$this->token->token, [
                'query' => 'Do you have a small unit?',
                'turnId' => 'turn-camel',
                'sessionId' => 'session-camel',
            ])
            ->assertOk();

        $this->assertTrue(VoiceSession::query()->where('bridge_session_id', 'session-camel')->exists());
        $this->assertTrue(VoiceSessionTurn::query()->where('turn_id', 'turn-camel')->exists());
    }

    #[Test]
    public function replayed_turn_id_returns_the_same_text_without_a_second_runtime_call(): void
    {
        $this->enqueueSafeReply();

        $first = $this->postBridge(['turn_id' => 'turn-replay'])->assertOk();
        $messagesAfterFirst = AgentConversationMessage::query()->count();
        $this->assertSame(1, $this->driver->callCount);

        $second = $this->postBridge(['turn_id' => 'turn-replay'])->assertOk();

        $this->assertSame($first->json('text'), $second->json('text'));
        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame($messagesAfterFirst, AgentConversationMessage::query()->count());
        $this->assertSame(1, VoiceSessionTurn::query()->count());
    }

    #[Test]
    public function runtime_throw_returns_200_handoff_and_persists_the_sentence(): void
    {
        $handoff = (string) config('agents.voice.handoff_sentence');

        $this->postBridge(['turn_id' => 'turn-throw'])
            ->assertOk()
            ->assertJsonPath('text', $handoff)
            ->assertJsonPath('transfer', true);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'ai.voice.turn_failed')->exists()
            || SystemEvent::query()->where('event', 'ai.turn.failed')->exists(),
        );
        $this->assertSame(1, VoiceSessionTurn::query()->count());
        $this->assertSame($handoff, VoiceSessionTurn::query()->value('answer_text'));

        $afterThrow = $this->driver->callCount;
        $messages = AgentConversationMessage::query()->count();

        $this->postBridge(['turn_id' => 'turn-throw'])
            ->assertOk()
            ->assertJsonPath('text', $handoff);

        $this->assertSame($afterThrow, $this->driver->callCount);
        $this->assertSame($messages, AgentConversationMessage::query()->count());
    }

    #[Test]
    public function binding_off_returns_handoff_without_running_the_runtime(): void
    {
        $this->bindVoice(BindingMode::Off, BindingAudience::All);

        $this->postBridge()
            ->assertOk()
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('transfer', true);

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(0, AgentConversation::query()->count());
        $this->assertSame(0, VoiceSession::query()->count());
    }

    #[Test]
    public function leftover_draft_binding_is_treated_as_off(): void
    {
        $this->bindVoice(BindingMode::Draft, BindingAudience::All);

        $this->postBridge()
            ->assertOk()
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('transfer', true);

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(0, AgentConversation::query()->count());
    }

    #[Test]
    public function known_contacts_binding_hands_off_an_unmatched_caller(): void
    {
        $this->bindVoice(BindingMode::Auto, BindingAudience::KnownContacts);

        $this->postBridge(['caller_number' => '+34911000999'])
            ->assertOk()
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('transfer', true);

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(0, AgentConversation::query()->count());
    }

    #[Test]
    public function known_contact_opens_a_channel_asserted_conversation(): void
    {
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+34911000001',
            'is_primary' => true,
            'opted_in' => true,
        ]);
        $this->bindVoice(BindingMode::Auto, BindingAudience::KnownContacts);
        $this->enqueueSafeReply();

        $this->postBridge(['caller_number' => '+34 911 000 001'])->assertOk();

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame(VerificationLevel::ChannelAsserted, $conversation->verification_level);
        $this->assertNotSame(VerificationLevel::Verified, $conversation->verification_level);
    }

    #[Test]
    public function over_budget_valid_token_returns_200_handoff_and_does_not_pin_the_turn(): void
    {
        config(['agents.voice.bridge_rate_per_minute' => 1]);
        $this->enqueueSafeReply();
        $this->enqueueSafeReply();

        $this->postBridge(['turn_id' => 'turn-ok'])->assertOk()->assertJsonPath('transfer', false);
        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame(1, VoiceSessionTurn::query()->count());

        $this->postBridge(['turn_id' => 'turn-throttled'])
            ->assertOk()
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('transfer', true);

        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame(1, VoiceSessionTurn::query()->count());
        $this->assertFalse(VoiceSessionTurn::query()->where('turn_id', 'turn-throttled')->exists());
        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.bridge_throttled')->exists());

        RateLimiter::clear('voice-bridge|'.$this->token->id);

        $this->postBridge(['turn_id' => 'turn-throttled'])
            ->assertOk()
            ->assertJsonPath('transfer', false);

        $this->assertSame(2, $this->driver->callCount);
        $this->assertTrue(VoiceSessionTurn::query()->where('turn_id', 'turn-throttled')->exists());
    }

    #[Test]
    public function over_budget_on_a_bad_secret_stays_401(): void
    {
        config(['agents.voice.bridge_rate_per_minute' => 1]);
        $this->enqueueSafeReply();
        $this->postBridge(['turn_id' => 'turn-ok'])->assertOk();

        $this->postBridge(['turn_id' => 'turn-bad'], ['X-Voice-Bridge-Secret' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('text');

        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.bridge_auth_failed')->exists());
        $this->assertFalse(SystemEvent::query()->where('event', 'ai.voice.bridge_throttled')->exists());
    }

    #[Test]
    public function missing_fields_after_auth_return_handoff_not_a_validation_error(): void
    {
        $this->postBridge(['query' => '', 'turn_id' => 't', 'session_id' => 's'])
            ->assertOk()
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('transfer', true);

        $this->assertSame(0, $this->driver->callCount);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     */
    private function postBridge(array $overrides = [], array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'X-Voice-Bridge-Secret' => $this->secret,
        ], $headers))->postJson(
            '/api/voice/bridge/'.$this->token->token,
            $this->payload($overrides),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'query' => 'Do you have a small unit near the centre?',
            'turn_id' => 'turn-1',
            'session_id' => 'vb-session-1',
        ], $overrides);
    }

    private function bindVoice(BindingMode $mode, BindingAudience $audience): void
    {
        $existing = AgentChannelBinding::query()
            ->where('channel', AgentChannel::Voice)
            ->where('site_id', $this->site->id)
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
            'site_id' => $this->site->id,
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
