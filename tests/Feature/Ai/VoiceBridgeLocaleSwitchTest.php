<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Country;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Support\Ai\DisclosureSentence;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeLocaleSwitchTest extends TestCase
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
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
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
    public function first_delegated_english_utterance_switches_locale_and_disclosure(): void
    {
        $this->driver->enqueueText('The small unit is available.');

        $response = $this->postBridge([
            'query' => 'How much is a small unit?',
            'caller_utterance' => 'How much does a small unit cost please',
        ])->assertOk();

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame('en', $conversation->locale);

        $english = DisclosureSentence::for('en');
        $spanish = DisclosureSentence::for('es');
        $this->assertStringContainsString($english, (string) $response->json('text'));
        $this->assertStringNotContainsString($spanish, (string) $response->json('text'));

        $event = SystemEvent::query()->where('event', 'ai.voice.locale_switched')->first();
        $this->assertNotNull($event);
        $this->assertSame('es', $event->payload['from'] ?? null);
        $this->assertSame('en', $event->payload['to'] ?? null);
    }

    #[Test]
    public function a_short_utterance_leaves_the_site_locale_alone(): void
    {
        $this->driver->enqueueText('Hay unidades disponibles.');

        $response = $this->postBridge([
            'query' => 'sí',
            'caller_utterance' => 'sí',
        ])->assertOk();

        $conversation = AgentConversation::query()->firstOrFail();
        $this->assertSame('es', $conversation->locale);
        $this->assertStringContainsString(DisclosureSentence::for('es'), (string) $response->json('text'));
        $this->assertSame(0, SystemEvent::query()->where('event', 'ai.voice.locale_switched')->count());
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
