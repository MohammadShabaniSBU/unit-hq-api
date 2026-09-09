<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AgentGuardrailEvent;
use App\Models\AiAgent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\VoiceBridgeToken;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Drivers\ModelResponse;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Ai\Trace\TraceSeq;
use Closure;
use Database\Seeders\AiAgentSeeder;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeGuardrailSeqTest extends TestCase
{
    use RefreshDatabase;

    private FakeModelDriver $driver;

    private VoiceBridgeToken $token;

    private string $secret = 'bridge-secret-value-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);

        $this->seed(AiAgentSeeder::class);
        $agent = AiAgent::query()->where('key', 'concierge')->firstOrFail();
        $site = Site::factory()->create();
        $this->token = VoiceBridgeToken::factory()->create([
            'site_id' => $site->id,
            'secret' => $this->secret,
            'secret_previous' => null,
        ]);
        RateLimiter::clear('voice-bridge|'.$this->token->id);
        RateLimiter::clear('ai-provider:voice');
        RateLimiter::clear('ai-provider:batch');

        Setting::setGeneral(Setting::general()->with(sendWindowStart: '00:00', sendWindowEnd: null));

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Voice,
            'site_id' => $site->id,
            'mode' => BindingMode::Auto,
            'audience' => BindingAudience::All,
            'outside_hours' => OutsideHoursPolicy::Answer,
        ]);
    }

    #[Test]
    public function unique_violation_that_is_not_a_turn_replay_returns_handoff(): void
    {
        $this->app->instance(ModelDriver::class, new class implements ModelDriver
        {
            public function stream(array $messages, array $tools, string $model, ?Closure $onDelta, ?int $timeoutSeconds = null): ModelResponse
            {
                throw new UniqueConstraintViolationException(
                    'pgsql',
                    'insert into "agent_guardrail_events"',
                    [],
                    new Exception('duplicate key value violates unique constraint "agent_guardrail_events_agent_conversation_id_seq_unique"'),
                );
            }
        });

        $this->postBridge(['turn_id' => 'turn-seq-race'])
            ->assertOk()
            ->assertJsonPath('text', config('agents.voice.handoff_sentence'))
            ->assertJsonPath('transfer', true);
    }

    #[Test]
    public function stolen_guardrail_seqs_are_retried_and_the_turn_returns_200(): void
    {
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $this->app->instance(ModelDriver::class, new class($this->driver) implements ModelDriver
        {
            public function __construct(private FakeModelDriver $inner) {}

            public function stream(array $messages, array $tools, string $model, ?Closure $onDelta, ?int $timeoutSeconds = null): ModelResponse
            {
                $conversation = AgentConversation::query()->first();
                if ($conversation !== null) {
                    $max = TraceSeq::max($conversation->id);
                    for ($i = 1; $i <= 8; $i++) {
                        AgentGuardrailEvent::query()->create([
                            'agent_conversation_id' => $conversation->id,
                            'turn' => 1,
                            'seq' => $max + $i,
                            'guard' => 'stolen',
                            'verdict' => 'pass',
                            'prompt_version' => 'test',
                        ]);
                    }
                }

                return $this->inner->stream($messages, $tools, $model, $onDelta, $timeoutSeconds);
            }
        });

        $this->postBridge(['session_id' => 'vb-seq-retry', 'turn_id' => 'turn-seq-retry'])
            ->assertOk()
            ->assertJsonPath('transfer', false);

        $seqs = AgentGuardrailEvent::query()
            ->orderBy('seq')
            ->pluck('seq')
            ->all();

        $this->assertSame($seqs, array_values(array_unique($seqs)));
        $this->assertSame(8, AgentGuardrailEvent::query()->where('guard', 'stolen')->count());
        $this->assertGreaterThan(
            0,
            AgentGuardrailEvent::query()->where('guard', '!=', 'stolen')->count(),
        );
    }

    #[Test]
    public function two_turns_on_the_same_session_write_distinct_guardrail_seqs(): void
    {
        $this->driver->enqueueText('We have units available. Which size are you looking for?');
        $this->driver->enqueueText('A small unit is available this week.');

        $first = $this->postBridge(['session_id' => 'vb-seq-two', 'turn_id' => 'turn-a'])->assertOk();
        $second = $this->postBridge(['session_id' => 'vb-seq-two', 'turn_id' => 'turn-b'])->assertOk();

        $this->assertFalse($first->json('transfer'));
        $this->assertFalse($second->json('transfer'));

        $seqs = AgentGuardrailEvent::query()
            ->orderBy('seq')
            ->pluck('seq')
            ->all();

        $this->assertSame($seqs, array_values(array_unique($seqs)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBridge(array $overrides = []): TestResponse
    {
        return $this->withHeaders([
            'X-Voice-Bridge-Secret' => $this->secret,
        ])->postJson('/api/voice/bridge/'.$this->token->token, array_merge([
            'query' => 'Do you have a small unit near the centre?',
            'turn_id' => 'turn-1',
            'session_id' => 'vb-session-1',
        ], $overrides));
    }
}
