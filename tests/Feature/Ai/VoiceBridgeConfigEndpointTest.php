<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Country;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'bridge-secret-value-for-tests';

    #[Test]
    public function valid_token_returns_config_shape_for_the_tokens_site(): void
    {
        $country = Country::factory()->create(['code' => 'GB']);
        $entity = LegalEntity::factory()->create([
            'legal_name' => 'Acme Storage Limited',
            'trading_name' => 'Acme Storage',
        ]);
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
        ]);
        $token = VoiceBridgeToken::factory()->create([
            'site_id' => $site->id,
            'secret' => $this->secret,
            'main_line_number' => null,
            'voicemail_number' => null,
        ]);

        $response = $this->getConfig($token)->assertOk();

        $this->assertSame('Acme Storage', $response->json('company_name'));
        $this->assertSame('en', $response->json('locale'));
        $this->assertSame('I am an automated assistant for Acme Storage.', $response->json('greeting'));
        $this->assertSame(config('ai-handoff.voice_filler'), $response->json('filler'));
        $this->assertSame(config('ai-handoff.voice_prompt_additions'), $response->json('prompt_additions'));
        $this->assertNull($response->json('transfer.main_line_number'));
        $this->assertNull($response->json('transfer.voicemail_number'));
        $this->assertSame(30, $response->json('max_call_duration_minutes'));
    }

    #[Test]
    public function transfer_numbers_on_the_token_are_returned(): void
    {
        $token = VoiceBridgeToken::factory()->create([
            'secret' => $this->secret,
            'main_line_number' => '+15551110000',
            'voicemail_number' => '+15551119999',
        ]);

        $this->getConfig($token)
            ->assertOk()
            ->assertJsonPath('transfer.main_line_number', '+15551110000')
            ->assertJsonPath('transfer.voicemail_number', '+15551119999');
    }

    #[Test]
    public function two_tokens_on_sites_with_different_countries_return_different_locale_and_greeting(): void
    {
        $esCountry = Country::factory()->create(['code' => 'ES']);
        $gbCountry = Country::factory()->create(['code' => 'GB']);

        $esEntity = LegalEntity::factory()->create([
            'legal_name' => 'Almacenajes Sur SL',
            'trading_name' => 'Almacenajes Sur',
        ]);
        $gbEntity = LegalEntity::factory()->create([
            'legal_name' => 'North Storage Ltd',
            'trading_name' => 'North Storage',
        ]);

        $esToken = VoiceBridgeToken::factory()->create([
            'secret' => $this->secret,
            'site_id' => Site::factory()->create([
                'country_id' => $esCountry->id,
                'legal_entity_id' => $esEntity->id,
            ])->id,
        ]);
        $gbToken = VoiceBridgeToken::factory()->create([
            'secret' => $this->secret,
            'site_id' => Site::factory()->create([
                'country_id' => $gbCountry->id,
                'legal_entity_id' => $gbEntity->id,
            ])->id,
        ]);

        $es = $this->getConfig($esToken)->assertOk();
        $gb = $this->getConfig($gbToken)->assertOk();

        $this->assertSame('es', $es->json('locale'));
        $this->assertSame('Soy un asistente automatizado de Almacenajes Sur.', $es->json('greeting'));
        $this->assertSame('en', $gb->json('locale'));
        $this->assertSame('I am an automated assistant for North Storage.', $gb->json('greeting'));
        $this->assertNotSame($es->json('locale'), $gb->json('locale'));
        $this->assertNotSame($es->json('greeting'), $gb->json('greeting'));
    }

    #[Test]
    public function site_without_trading_name_falls_back_to_legal_name(): void
    {
        $entity = LegalEntity::factory()->create([
            'legal_name' => 'Registered Name Ltd',
            'trading_name' => null,
        ]);
        $site = Site::factory()->create([
            'legal_entity_id' => $entity->id,
        ]);
        $token = VoiceBridgeToken::factory()->create([
            'site_id' => $site->id,
            'secret' => $this->secret,
        ]);

        $this->getConfig($token)
            ->assertOk()
            ->assertJsonPath('company_name', 'Registered Name Ltd');
    }

    #[Test]
    public function unknown_token_is_401_without_an_event(): void
    {
        $this->getJson('/api/voice/bridge/not-a-real-token/config', [
            'X-Voice-Bridge-Secret' => $this->secret,
        ])
            ->assertUnauthorized()
            ->assertJsonMissingPath('greeting');

        $this->assertSame(0, SystemEvent::query()->where('event', 'ai.voice.bridge_auth_failed')->count());
    }

    #[Test]
    public function bad_secret_is_401_and_records_one_auth_failed_event(): void
    {
        $token = VoiceBridgeToken::factory()->create([
            'secret' => $this->secret,
        ]);

        $this->getConfig($token, ['X-Voice-Bridge-Secret' => 'not-the-secret'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('greeting');

        $events = SystemEvent::query()->where('event', 'ai.voice.bridge_auth_failed')->get();
        $this->assertCount(1, $events);
        $this->assertSame($token->id, $events->first()?->subject_id);
        $this->assertSame('bad_secret', $events->first()?->payload['reason'] ?? null);
        $this->assertStringNotContainsString($this->secret, (string) json_encode($events->first()?->payload));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function getConfig(VoiceBridgeToken $token, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'X-Voice-Bridge-Secret' => $this->secret,
        ], $headers))->getJson('/api/voice/bridge/'.$token->token.'/config');
    }
}
