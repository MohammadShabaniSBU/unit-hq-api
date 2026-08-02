<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\Employee;
use App\Models\EsignProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ESignAccountTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);
        Config::set('app.url', 'https://example.com');
    }

    public function test_connect_verify_discipline(): void
    {
        Http::fake([
            'api.signable.co.uk/v1/envelopes*' => Http::sequence()
                ->push(['http' => 401, 'message' => 'Unauthorized'], 401)
                ->push(['http' => 200, 'envelopes' => []], 200)
                ->push(['http' => 200, 'envelopes' => []], 200)
                ->push(['http' => 200, 'envelopes' => []], 200),
            'api.signable.co.uk/v1/webhooks*' => Http::response([
                'webhook_fingerprint' => 'wh_test_1',
            ], 201),
        ]);

        $error = $this->putJson('/api/settings/esign', [
            'provider' => 'signable',
            'credentials' => ['api_key' => 'bad_key_xxxx'],
        ]);

        $error->assertOk()
            ->assertJsonPath('data.accounts.0.status', 'error')
            ->assertJsonPath('data.accounts.0.credentials.api_key.masked', '••••••xxxx')
            ->assertJsonMissingPath('data.accounts.0.credentials.api_key.value');

        $this->assertNotNull(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'esign_provider_account.created')
                ->first()
        );

        $ok = $this->putJson('/api/settings/esign', [
            'provider' => 'signable',
            'credentials' => ['api_key' => 'sk_test_discipline_abcd'],
        ]);

        $ok->assertOk()
            ->assertJsonPath('data.accounts.0.status', 'connected')
            ->assertJsonPath('data.accounts.0.credentials.api_key.masked', '••••••abcd')
            ->assertJsonPath('data.accounts.0.credentials.api_key.has_value', true)
            ->assertJsonPath('data.accounts.0.webhook_state', 'unconfigured')
            ->assertJsonPath('data.active_provider', 'signable');

        $this->assertStringContainsString(
            '/api/webhooks/esign/',
            (string) $ok->json('data.accounts.0.webhook_url')
        );

        $blank = $this->putJson('/api/settings/esign', [
            'provider' => 'signable',
            'credentials' => ['api_key' => ''],
        ]);
        $blank->assertOk()->assertJsonPath('data.accounts.0.status', 'connected');

        $account = EsignProviderAccount::query()->firstOrFail();
        $this->assertSame('sk_test_discipline_abcd', $account->credentials['api_key']);

        $this->putJson('/api/settings/esign', [
            'provider' => 'signable',
            'credentials' => ['api_key' => 'sk_test_rotated_wxyz'],
        ])->assertOk();

        $this->assertNotNull(
            Activity::query()
                ->where('description', 'esign_provider_account.rotated')
                ->first()
        );

        $webhook = $this->postJson('/api/settings/esign/webhook');
        $webhook->assertOk()
            ->assertJsonPath('data.accounts.0.webhook_state', 'configured');

        // Corrupt ciphertext → credentials_unreadable, not 500.
        DB::table('esign_provider_accounts')
            ->where('id', $account->id)
            ->update(['credentials' => 'not-valid-laravel-ciphertext']);

        $show = $this->getJson('/api/settings/esign');
        $show->assertOk()
            ->assertJsonPath('data.accounts.0.credentials_unreadable', true);

        $this->deleteJson('/api/settings/esign')->assertNoContent();

        $this->assertNotNull(
            Activity::query()
                ->where('description', 'esign_provider_account.removed')
                ->first()
        );

        $this->assertDatabaseCount('esign_provider_accounts', 0);
    }
}
