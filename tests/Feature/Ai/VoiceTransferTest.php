<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentHandoff;
use App\Models\AiAgent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\VoiceToolSurface;
use App\Support\Ai\VoiceTransfer;
use Carbon\CarbonImmutable;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceTransferTest extends TestCase
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
        $this->site = Site::factory()->create(['timezone' => 'Europe/Madrid']);
        $this->token = VoiceBridgeToken::factory()->create([
            'site_id' => $this->site->id,
            'secret' => $this->secret,
            'secret_previous' => null,
        ]);
        RateLimiter::clear('voice-bridge|'.$this->token->id);

        Setting::setGeneral(Setting::general()->with(sendWindowStart: '09:00', sendWindowEnd: '17:00'));
        $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00', $this->site->timezone));
        $this->bindVoice(BindingMode::Auto, BindingAudience::All, OutsideHoursPolicy::Answer);
    }

    #[Test]
    public function canned_voice_sentences_are_digit_free_and_within_the_voice_profile(): void
    {
        $profile = ChannelProfile::for(AgentChannel::Voice);
        $sentences = [
            (string) config('agents.voice.apology_sentence'),
            (string) config('agents.voice.voicemail_sentence'),
            (string) config('agents.voice.handoff_sentence'),
            CannedReply::Error,
        ];

        foreach ($sentences as $sentence) {
            $this->assertNotSame('', $sentence);
            $this->assertDoesNotMatchRegularExpression('/\d/', $sentence);
            $this->assertLessThanOrEqual($profile->maxCharacters, mb_strlen($sentence));
            $this->assertLessThanOrEqual($profile->targetSentences, $this->sentenceCount($sentence));
        }
    }

    #[Test]
    public function runtime_escalate_transfers_to_main_line_and_returns_the_draft_verbatim(): void
    {
        $this->enqueueEscalate(HandoffReason::CustomerRequested);

        $response = $this->postBridge(['turn_id' => 'turn-escalate'])->assertOk();

        $this->assertTrue($response->json('transfer'));
        $this->assertSame('main_line', $response->json('destination'));
        $this->assertDoesNotMatchRegularExpression('/\d/', (string) $response->json('text'));
        $this->assertStringNotContainsString('+', (string) $response->json('text'));

        $draft = AgentConversationMessage::query()
            ->where('role', 'assistant')
            ->where('finish_reason', 'handoff')
            ->value('content');
        $this->assertIsString($draft);
        $this->assertSame($draft, $response->json('text'));

        $this->assertSame(1, AgentHandoff::query()->count());
        $this->assertFalse(app(ToolRegistry::class)->has('voice.transfer'));
        $this->assertNotContains('voice.transfer', VoiceToolSurface::keys());
    }

    #[Test]
    public function out_of_hours_remaps_error_and_verification_required_to_voicemail(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 02:00:00', $this->site->timezone));

        $this->enqueueEscalate(HandoffReason::VerificationRequired);
        $this->postBridge(['turn_id' => 'turn-verify'])
            ->assertOk()
            ->assertJsonPath('transfer', true)
            ->assertJsonPath('destination', 'voicemail');

        $this->postBridge(['turn_id' => 'turn-throw'])
            ->assertOk()
            ->assertJsonPath('transfer', true)
            ->assertJsonPath('destination', 'voicemail')
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'));
    }

    #[Test]
    public function inbox_outside_hours_transfers_to_voicemail_without_running_the_runtime(): void
    {
        $this->bindVoice(BindingMode::Auto, BindingAudience::All, OutsideHoursPolicy::Inbox);
        $this->travelTo(CarbonImmutable::parse('2026-09-01 02:00:00', $this->site->timezone));

        $this->postBridge(['turn_id' => 'turn-ooh'])
            ->assertOk()
            ->assertJsonPath('transfer', true)
            ->assertJsonPath('destination', 'voicemail')
            ->assertJsonPath('text', config('agents.voice.voicemail_sentence'));

        $this->assertSame(0, $this->driver->callCount);
        $this->assertSame(1, VoiceSession::query()->count());
        $this->assertSame(1, AgentConversation::query()->count());

        $handoff = AgentHandoff::query()->firstOrFail();
        $this->assertSame(HandoffReason::OutOfHours, $handoff->reason);
        $this->assertSame(HandoffTriggerSource::Rule, $handoff->trigger_source);
        $this->assertSame(ConversationState::AwaitingHuman, AgentConversation::query()->firstOrFail()->state);
        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.outside_hours')->exists());
    }

    #[Test]
    public function answer_outside_hours_still_runs_the_runtime_and_remaps_escalate_to_voicemail(): void
    {
        $this->bindVoice(BindingMode::Auto, BindingAudience::All, OutsideHoursPolicy::Answer);
        $this->travelTo(CarbonImmutable::parse('2026-09-01 02:00:00', $this->site->timezone));
        $this->enqueueEscalate(HandoffReason::CustomerRequested);

        $this->postBridge(['turn_id' => 'turn-answer-ooh'])
            ->assertOk()
            ->assertJsonPath('transfer', true)
            ->assertJsonPath('destination', 'voicemail');

        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame(HandoffReason::CustomerRequested, AgentHandoff::query()->firstOrFail()->reason);
    }

    #[Test]
    public function unmapped_reason_falls_back_to_main_line_and_logs(): void
    {
        $map = config('agents.voice.reason_destinations');
        $this->assertIsArray($map);
        unset($map[HandoffReason::CustomerRequested->value]);
        config(['agents.voice.reason_destinations' => $map]);

        $this->enqueueEscalate(HandoffReason::CustomerRequested);
        $this->postBridge(['turn_id' => 'turn-unmapped'])
            ->assertOk()
            ->assertJsonPath('transfer', true)
            ->assertJsonPath('destination', VoiceTransfer::MainLine);

        $events = SystemEvent::query()->where('event', 'ai.voice.transfer_unmapped')->get();
        $this->assertCount(1, $events);
        $this->assertSame(HandoffReason::CustomerRequested->value, $events->first()?->payload['reason'] ?? null);
        $this->assertArrayNotHasKey('number', $events->first()?->payload ?? []);
        $this->assertStringNotContainsString('+', (string) json_encode($events->first()?->payload));
    }

    #[Test]
    public function empty_approved_destinations_fails_closed_with_an_apology(): void
    {
        config(['agents.voice.approved_destinations' => []]);
        $this->enqueueEscalate(HandoffReason::CustomerRequested);

        $response = $this->postBridge(['turn_id' => 'turn-closed'])->assertOk();

        $this->assertFalse($response->json('transfer'));
        $this->assertSame(config('agents.voice.apology_sentence'), $response->json('text'));
        $this->assertArrayNotHasKey('destination', $response->json());
        $this->assertTrue(SystemEvent::query()->where('event', 'ai.voice.transfer_unmapped')->exists());
    }

    #[Test]
    public function replayed_turn_returns_the_same_destination(): void
    {
        $this->enqueueEscalate(HandoffReason::CustomerRequested);

        $first = $this->postBridge(['turn_id' => 'turn-replay-dest'])->assertOk();
        $second = $this->postBridge(['turn_id' => 'turn-replay-dest'])->assertOk();

        $this->assertTrue($first->json('transfer'));
        $this->assertSame($first->json('destination'), $second->json('destination'));
        $this->assertSame($first->json('text'), $second->json('text'));
        $this->assertSame(1, $this->driver->callCount);
        $this->assertSame(1, VoiceSessionTurn::query()->count());
        $this->assertSame('main_line', VoiceSessionTurn::query()->value('destination'));
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
                'query' => 'How much do I owe right now?',
                'turn_id' => 'turn-1',
                'session_id' => 'vb-session-1',
            ], $overrides),
        );
    }

    private function enqueueEscalate(HandoffReason $reason): void
    {
        $this->driver->enqueueToolCalls([[
            'name' => 'agent.escalate',
            'id' => 'esc1',
            'arguments' => [
                'reason' => $reason->value,
                'summary' => 'Needs a person',
            ],
        ]]);
    }

    private function bindVoice(BindingMode $mode, BindingAudience $audience, OutsideHoursPolicy $hours): void
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
                'outside_hours' => $hours,
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
            'outside_hours' => $hours,
        ]);
    }

    private function sentenceCount(string $text): int
    {
        $parts = preg_split('/[.!?]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return 0;
        }

        return count(array_filter(array_map(trim(...), $parts), fn (string $part): bool => $part !== ''));
    }
}
