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
use App\Models\Setting;
use App\Models\Site;
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
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Ai\Enums\VerificationLevel;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeA2aEndpointTest extends TestCase
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
        $this->bindVoice(BindingMode::Auto, BindingAudience::All);
    }

    #[Test]
    public function first_message_send_without_context_id_creates_session_and_returns_context_id(): void
    {
        $this->enqueueSafeReply();

        $response = $this->postA2a([
            'messageId' => 'msg-1',
            'parts' => [['kind' => 'text', 'text' => 'Do you have a small unit?']],
        ])->assertOk();

        $contextId = $response->json('result.contextId');
        $this->assertIsString($contextId);
        $this->assertNotSame('', $contextId);
        $this->assertSame('2.0', $response->json('jsonrpc'));
        $this->assertSame('req-1', $response->json('id'));
        $this->assertSame('agent', $response->json('result.role'));
        $this->assertIsString($response->json('result.parts.0.text'));
        $this->assertNotSame('', $response->json('result.parts.0.text'));
        $this->assertFalse($response->json('result.metadata.transfer'));
        $this->assertArrayNotHasKey('error', $response->json());

        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame(1, AgentConversation::query()->count());
        $this->assertTrue(VoiceSession::query()->where('bridge_session_id', $contextId)->exists());
    }

    #[Test]
    public function second_message_send_reuses_session_and_conversation(): void
    {
        $this->enqueueSafeReply();
        $this->enqueueSafeReply();

        $first = $this->postA2a([
            'messageId' => 'msg-1',
            'parts' => [['kind' => 'text', 'text' => 'Do you have storage?']],
        ])->assertOk();

        $contextId = $first->json('result.contextId');
        $this->assertIsString($contextId);

        $this->postA2a([
            'messageId' => 'msg-2',
            'contextId' => $contextId,
            'parts' => [['kind' => 'text', 'text' => 'What sizes do you have?']],
        ], 'req-2')->assertOk();

        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame(1, AgentConversation::query()->count());
        $this->assertSame(2, VoiceSessionTurn::query()->count());

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame(AgentOrigin::Voice, $conversation->origin);
        $this->assertSame(AgentAudience::Customer, $conversation->audience);
        $this->assertSame(AgentChannel::Voice, $conversation->channel);
        $this->assertSame($this->site->id, $conversation->site_id);
        $this->assertSame(VerificationLevel::Anonymous, $conversation->verification_level);
    }

    #[Test]
    public function replayed_message_id_returns_the_same_text_without_a_second_runtime_call(): void
    {
        $this->enqueueSafeReply();

        $first = $this->postA2a([
            'messageId' => 'msg-replay',
            'contextId' => 'ctx-replay',
            'parts' => [['kind' => 'text', 'text' => 'Do you have a small unit?']],
        ])->assertOk();
        $messagesAfterFirst = AgentConversationMessage::query()->count();
        $this->assertSame(1, $this->driver->callCount);

        $second = $this->postA2a([
            'messageId' => 'msg-replay',
            'contextId' => 'ctx-replay',
            'parts' => [['kind' => 'text', 'text' => 'Do you have a small unit?']],
        ], 'req-2')->assertOk();

        $this->assertSame($first->json('result.parts.0.text'), $second->json('result.parts.0.text'));
        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame($messagesAfterFirst, AgentConversationMessage::query()->count());
        $this->assertSame(1, VoiceSessionTurn::query()->count());
    }

    #[Test]
    public function transfer_round_trips_in_a2a_metadata(): void
    {
        $this->driver->enqueueToolCalls([[
            'name' => 'agent.escalate',
            'id' => 'esc1',
            'arguments' => [
                'reason' => HandoffReason::CustomerRequested->value,
                'summary' => 'Needs a person',
            ],
        ]]);

        $response = $this->postA2a([
            'messageId' => 'msg-transfer',
            'contextId' => 'ctx-transfer',
            'parts' => [['kind' => 'text', 'text' => 'I want to speak to someone.']],
        ])->assertOk();

        $this->assertTrue($response->json('result.metadata.transfer'));
        $this->assertSame('main_line', $response->json('result.metadata.destination'));
        $this->assertIsString($response->json('result.parts.0.text'));
        $this->assertArrayNotHasKey('error', $response->json());
    }

    #[Test]
    public function binding_off_returns_a2a_handoff_not_a_jsonrpc_error(): void
    {
        $this->bindVoice(BindingMode::Off, BindingAudience::All);

        $response = $this->postA2a([
            'messageId' => 'msg-off',
            'contextId' => 'ctx-off',
            'parts' => [['kind' => 'text', 'text' => 'Do you have a small unit?']],
        ])->assertOk();

        $this->assertSame(config('agents.voice.handoff_sentence'), $response->json('result.parts.0.text'));
        $this->assertTrue($response->json('result.metadata.transfer'));
        $this->assertSame('main_line', $response->json('result.metadata.destination'));
        $this->assertArrayNotHasKey('error', $response->json());
        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(0, VoiceSession::query()->count());
    }

    #[Test]
    public function missing_text_after_auth_returns_a2a_handoff(): void
    {
        $this->postA2a([
            'messageId' => 'msg-empty',
            'contextId' => 'ctx-empty',
            'parts' => [],
        ])
            ->assertOk()
            ->assertJsonPath('result.parts.0.text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('result.metadata.transfer', true);

        $this->assertSame(0, $this->driver->callCount);
    }

    #[Test]
    public function known_contact_via_metadata_opens_a_channel_asserted_conversation(): void
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

        $this->postA2a([
            'messageId' => 'msg-known',
            'contextId' => 'ctx-known',
            'parts' => [['kind' => 'text', 'text' => 'Do you have a small unit?']],
            'metadata' => ['caller_number' => '+34 911 000 001'],
        ])->assertOk();

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame(VerificationLevel::ChannelAsserted, $conversation->verification_level);
    }

    #[Test]
    public function bad_secret_stays_a_flat_401(): void
    {
        $this->postA2a([
            'messageId' => 'msg-bad',
            'parts' => [['kind' => 'text', 'text' => 'Hello']],
        ], 'req-1', ['X-Voice-Bridge-Secret' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('result')
            ->assertJsonMissingPath('text');

        $this->assertSame(0, $this->driver->callCount);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $headers
     */
    private function postA2a(array $message, string $id = 'req-1', array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'X-Voice-Bridge-Secret' => $this->secret,
        ], $headers))->postJson(
            '/api/voice/bridge/'.$this->token->token,
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'message/send',
                'params' => [
                    'message' => array_merge([
                        'role' => 'user',
                    ], $message),
                ],
            ],
        );
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
