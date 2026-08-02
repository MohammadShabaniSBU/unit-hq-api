<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\LogChannel;
use App\Models\AccessProviderAccount;
use App\Models\Activity;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessAccountTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);
        Config::set('app.url', 'https://example.com');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/oauth/token')) {
                parse_str($request->body(), $form);
                $secret = (string) ($form['client_secret'] ?? '');
                if ($secret === '' || str_starts_with($secret, 'bad_')) {
                    return Http::response(['error' => 'invalid_client'], 401);
                }

                return Http::response(['access_token' => 'tok_test_access'], 200);
            }

            if (str_contains($url, '/points')) {
                return Http::response([
                    'points' => [
                        ['id' => 'sb-gate-1', 'label' => 'Main gate', 'kind' => 'gate'],
                        ['id' => 'sb-door-1', 'label' => 'Unit AL6-06 door', 'kind' => 'unit_door'],
                    ],
                ], 200);
            }

            if (str_contains($url, '/webhooks')) {
                return Http::response(['id' => 'sb_wh_1'], 201);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });
    }

    public function test_discipline_modes_points(): void
    {
        $error = $this->putJson('/api/settings/access', [
            'provider' => 'sensorberg',
            'credentials' => [
                'client_id' => 'client_xxxx',
                'client_secret' => 'bad_secret_xxxx',
            ],
        ]);

        $error->assertOk()
            ->assertJsonPath('data.accounts.0.status', 'error')
            ->assertJsonPath('data.accounts.0.credentials.client_secret.masked', '••••••xxxx')
            ->assertJsonMissingPath('data.accounts.0.credentials.client_secret.value')
            ->assertJsonPath('data.provider_options.0.credential_modes.0', 'app_invite')
            ->assertJsonPath('data.provider_options.0.credential_modes.1', 'pin');

        $this->assertNotNull(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'access_provider_account.created')
                ->first()
        );

        $ok = $this->putJson('/api/settings/access', [
            'provider' => 'sensorberg',
            'credentials' => [
                'client_id' => 'client_abcd',
                'client_secret' => 'sk_test_discipline_abcd',
            ],
        ]);

        $ok->assertOk()
            ->assertJsonPath('data.accounts.0.status', 'connected')
            ->assertJsonPath('data.accounts.0.credentials.client_secret.masked', '••••••abcd')
            ->assertJsonPath('data.accounts.0.credentials.client_secret.has_value', true)
            ->assertJsonPath('data.accounts.0.credential_modes.0', 'app_invite')
            ->assertJsonPath('data.accounts.0.webhook_state', 'unconfigured')
            ->assertJsonPath('data.active_provider', 'sensorberg')
            ->assertJsonPath('data.accounts.0.discovered_points_count', 0);

        $this->assertStringContainsString(
            '/api/webhooks/access/',
            (string) $ok->json('data.accounts.0.webhook_url')
        );

        $blank = $this->putJson('/api/settings/access', [
            'provider' => 'sensorberg',
            'credentials' => [
                'client_id' => '',
                'client_secret' => '',
            ],
        ]);
        $blank->assertOk()->assertJsonPath('data.accounts.0.status', 'connected');

        $account = AccessProviderAccount::query()->firstOrFail();
        $this->assertSame('sk_test_discipline_abcd', $account->credentials['client_secret']);

        $this->putJson('/api/settings/access', [
            'provider' => 'sensorberg',
            'credentials' => [
                'client_secret' => 'sk_test_rotated_wxyz',
            ],
        ])->assertOk();

        $this->assertNotNull(
            Activity::query()
                ->where('description', 'access_provider_account.rotated')
                ->first()
        );

        $refresh = $this->postJson('/api/settings/access/points/refresh');
        $refresh->assertOk()
            ->assertJsonPath('data.accounts.0.discovered_points_count', 2)
            ->assertJsonPath('data.accounts.0.points_discovered_at', fn ($v) => is_string($v) && $v !== '');

        $account->refresh();
        $this->assertCount(2, $account->discovered_points ?? []);
        $this->assertNotNull($account->points_discovered_at);

        $webhook = $this->postJson('/api/settings/access/webhook');
        $webhook->assertOk()
            ->assertJsonPath('data.accounts.0.webhook_state', 'configured');

        DB::table('access_provider_accounts')
            ->where('id', $account->id)
            ->update(['credentials' => 'not-valid-laravel-ciphertext']);

        $show = $this->getJson('/api/settings/access');
        $show->assertOk()
            ->assertJsonPath('data.accounts.0.credentials_unreadable', true)
            ->assertJsonStructure([
                'data' => [
                    'attention' => ['unmapped_points_count', 'unresolved_contacts_count'],
                ],
            ]);

        $this->deleteJson('/api/settings/access')->assertNoContent();

        $this->assertNotNull(
            Activity::query()
                ->where('description', 'access_provider_account.removed')
                ->first()
        );

        $this->assertDatabaseCount('access_provider_accounts', 0);
    }
}
