<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSessionTurn;
use App\Models\VoiceTranscriptSegment;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Auth\Permission;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class VoiceBridgeTranscriptTest extends TestCase
{
    use GrantsSinglePermission;
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
    public function batch_creates_rows_unique_on_session_and_sequence(): void
    {
        $this->postOpen('vb-transcript-1')->assertOk();

        $this->postTranscript('vb-transcript-1', [
            $this->segment(1, 'caller', 'what are your hours?', 'stt'),
            $this->segment(2, 'agent', 'Let me check that for you.', 'fast_model'),
        ])->assertOk()->assertJsonPath('stored', 2);

        $this->assertSame(2, VoiceTranscriptSegment::query()->count());
        $this->assertSame(
            ['caller', 'agent'],
            VoiceTranscriptSegment::query()->orderBy('sequence')->pluck('role')->all(),
        );
        $this->assertSame(
            ['stt', 'fast_model'],
            VoiceTranscriptSegment::query()->orderBy('sequence')->pluck('source')->all(),
        );
    }

    #[Test]
    public function turn_id_resolves_to_the_stored_turn(): void
    {
        $this->postOpen('vb-transcript-1')->assertOk();
        $this->enqueueSafeReply();
        $this->postBridge(['session_id' => 'vb-transcript-1', 'turn_id' => 'sess:1:0'])->assertOk();

        $turn = VoiceSessionTurn::query()->where('turn_id', 'sess:1:0')->firstOrFail();

        $this->postTranscript('vb-transcript-1', [
            $this->segment(1, 'agent', 'We have units available. Which size are you looking for?', 'delegated', 'sess:1:0'),
        ])->assertOk();

        $segment = VoiceTranscriptSegment::query()->firstOrFail();
        $this->assertSame($turn->id, $segment->voice_session_turn_id);
        $this->assertSame('delegated', $segment->source);
    }

    #[Test]
    public function replay_does_not_duplicate_rows(): void
    {
        $this->postOpen('vb-transcript-1')->assertOk();
        $batch = [$this->segment(1, 'caller', 'hello', 'stt')];

        $this->postTranscript('vb-transcript-1', $batch)->assertOk()->assertJsonPath('stored', 1);
        $this->postTranscript('vb-transcript-1', $batch)->assertOk()->assertJsonPath('stored', 1);

        $this->assertSame(1, VoiceTranscriptSegment::query()->count());
        $this->assertSame('hello', VoiceTranscriptSegment::query()->value('text'));
    }

    #[Test]
    public function unknown_or_missing_secret_is_401(): void
    {
        $this->postOpen('vb-transcript-1')->assertOk();
        $this->flushHeaders();

        $this->postJson('/api/voice/bridge/'.$this->token->token.'/session/vb-transcript-1/transcript', [
            'segments' => [$this->segment(1, 'caller', 'hello', 'stt')],
        ])->assertUnauthorized();

        $this->withHeaders(['X-Voice-Bridge-Secret' => 'wrong-secret'])
            ->postJson('/api/voice/bridge/'.$this->token->token.'/session/vb-transcript-1/transcript', [
                'segments' => [$this->segment(1, 'caller', 'hello', 'stt')],
            ])
            ->assertUnauthorized();

        $this->assertSame(0, VoiceTranscriptSegment::query()->count());
    }

    #[Test]
    public function unknown_session_is_404(): void
    {
        $this->postTranscript('vb-never-opened', [
            $this->segment(1, 'caller', 'hello', 'stt'),
        ])->assertNotFound();

        $this->assertSame(0, VoiceTranscriptSegment::query()->count());
    }

    #[Test]
    public function delegated_segment_writes_round_trip_telemetry_onto_the_turn(): void
    {
        $opened = $this->postOpen('vb-transcript-1')->assertOk();
        $this->enqueueSafeReply();
        $this->postBridge(['session_id' => 'vb-transcript-1', 'turn_id' => 'sess:1:0'])->assertOk();

        $this->postTranscript('vb-transcript-1', [
            $this->segment(1, 'agent', 'We have units available. Which size are you looking for?', 'delegated', 'sess:1:0', [
                'round_trip_ms' => 1840,
                'filler_spoken' => true,
            ]),
        ])->assertOk();

        $turn = VoiceSessionTurn::query()->where('turn_id', 'sess:1:0')->firstOrFail();
        $this->assertSame(1840, $turn->round_trip_ms);
        $this->assertTrue($turn->filler_spoken);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $this->getJson('/api/voice-sessions/'.$opened->json('id'))
            ->assertOk()
            ->assertJsonPath('data.turns.0.round_trip_ms', 1840)
            ->assertJsonPath('data.turns.0.filler_spoken', true);
    }

    #[Test]
    public function telemetry_fields_do_not_stomp_when_only_one_arrives(): void
    {
        $this->postOpen('vb-transcript-1')->assertOk();
        $this->enqueueSafeReply();
        $this->postBridge(['session_id' => 'vb-transcript-1', 'turn_id' => 'sess:1:0'])->assertOk();

        $this->postTranscript('vb-transcript-1', [
            $this->segment(1, 'agent', 'We have units available. Which size are you looking for?', 'delegated', 'sess:1:0', [
                'filler_spoken' => true,
            ]),
        ])->assertOk();

        $this->postTranscript('vb-transcript-1', [
            $this->segment(1, 'agent', 'We have units available. Which size are you looking for?', 'delegated', 'sess:1:0', [
                'round_trip_ms' => 900,
            ]),
        ])->assertOk();

        $turn = VoiceSessionTurn::query()->where('turn_id', 'sess:1:0')->firstOrFail();
        $this->assertTrue($turn->filler_spoken);
        $this->assertSame(900, $turn->round_trip_ms);
    }

    #[Test]
    public function show_returns_segments_ordered_by_sequence(): void
    {
        $opened = $this->postOpen('vb-transcript-1')->assertOk();
        $this->postTranscript('vb-transcript-1', [
            $this->segment(2, 'agent', 'Let me check that for you.', 'fast_model'),
            $this->segment(1, 'caller', 'what are your hours?', 'stt'),
            $this->segment(3, 'agent', 'We are open from 8am to 6pm.', 'delegated'),
        ])->assertOk();

        Sanctum::actingAs($this->employeeWithPermission(Permission::AiAgentUse));

        $segments = $this->getJson('/api/voice-sessions/'.$opened->json('id'))
            ->assertOk()
            ->json('data.transcript_segments');

        $this->assertSame([1, 2, 3], array_column($segments, 'sequence'));
        $this->assertSame(['stt', 'fast_model', 'delegated'], array_column($segments, 'source'));
        $this->assertSame('what are your hours?', $segments[0]['text']);
        $this->assertSame('We are open from 8am to 6pm.', $segments[2]['text']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postOpen(string $bridgeSessionId, array $overrides = []): TestResponse
    {
        return $this->withHeaders(['X-Voice-Bridge-Secret' => $this->secret])
            ->postJson('/api/voice/bridge/'.$this->token->token.'/session', array_merge([
                'bridge_session_id' => $bridgeSessionId,
            ], $overrides));
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private function postTranscript(string $bridgeSessionId, array $segments): TestResponse
    {
        return $this->withHeaders(['X-Voice-Bridge-Secret' => $this->secret])
            ->postJson(
                '/api/voice/bridge/'.$this->token->token.'/session/'.$bridgeSessionId.'/transcript',
                ['segments' => $segments],
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
                'session_id' => 'vb-transcript-1',
            ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function segment(int $sequence, string $role, string $text, string $source, ?string $turnId = null, array $extra = []): array
    {
        $segment = [
            'sequence' => $sequence,
            'role' => $role,
            'text' => $text,
            'source' => $source,
            'occurred_at' => '2026-09-04T12:00:00Z',
        ];

        if ($turnId !== null) {
            $segment['turn_id'] = $turnId;
        }

        return array_merge($segment, $extra);
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
