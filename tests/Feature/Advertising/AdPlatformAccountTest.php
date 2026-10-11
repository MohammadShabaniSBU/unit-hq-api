<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Models\Activity;
use App\Models\AdPlatformAccount;
use App\Models\SystemEvent;
use App\Support\Advertising\AdPlatformException;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\Telescope;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AdPlatformAccountTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private const REFRESH_TOKEN = '1//refresh-secret-abcd';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingConnect));
    }

    #[Test]
    public function google_oauth_round_trip_stores_currency_and_rejects_a_replayed_state(): void
    {
        $this->fakeGoogle();

        $start = $this->postJson('/api/ad-platform-accounts/google/oauth/start');
        $start->assertOk();

        $query = [];
        parse_str((string) parse_url((string) $start->json('data.url'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';
        $this->assertNotSame('', $state);

        $callback = $this->get('/api/ad-platform-accounts/google/oauth/callback?code=auth-code&state='.$state);
        $callback->assertRedirect('http://localhost:3000/settings/advertising?'.http_build_query([
            'selection' => $this->selectionFrom($callback->headers->get('Location')),
        ]));

        $selection = $this->selectionFrom($callback->headers->get('Location'));

        $replay = $this->get('/api/ad-platform-accounts/google/oauth/callback?code=auth-code&state='.$state);
        $replay->assertRedirect();
        $this->assertStringContainsString('error=oauth_state_invalid', (string) $replay->headers->get('Location'));
        $this->assertSame(1, $this->sentCount('oauth2.googleapis.com/token'));

        $listed = $this->getJson('/api/ad-platform-accounts/google/oauth/selection?selection='.$selection);
        $listed->assertOk()->assertJsonPath('data.customers.0.id', '1234567890');
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) $listed->getContent());

        $created = $this->postJson('/api/ad-platform-accounts/google/oauth/complete', [
            'selection' => $selection,
            'name' => 'Google test',
            'external_account_id' => '123-456-7890',
            'login_customer_id' => '998-877',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.timezone', 'Europe/Madrid')
            ->assertJsonPath('data.external_account_id', '1234567890')
            ->assertJsonPath('data.login_customer_id', '998877')
            ->assertJsonPath('data.credentials.refresh_token.masked', '••••••abcd')
            ->assertJsonPath('data.credentials_unreadable', false)
            ->assertJsonPath('data.reports_conversions', true)
            ->assertJsonPath('data.reports_spend', true);

        $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) $created->getContent());

        $activity = Activity::query()->where('description', 'ad_platform_account.created')->first();
        $this->assertNotNull($activity);
        $this->assertSame('••••••abcd', $activity->properties['masked']);
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) json_encode($activity->properties));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'googleAds:searchStream')
                && $request->hasHeader('developer-token', 'dev-token')
                && $request->hasHeader('login-customer-id', '998877');
        });

        $this->assertContains('credentials', Telescope::$hiddenRequestParameters);
    }

    #[Test]
    public function meta_save_rejects_a_bad_pixel_token(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'act_99')) {
                return Http::response([
                    'currency' => 'EUR',
                    'timezone_name' => 'Europe/Madrid',
                    'account_status' => 1,
                ]);
            }

            return Http::response(['error' => 'bad pixel'], 400);
        });

        $this->postJson('/api/ad-platform-accounts', [
            'provider' => 'meta',
            'name' => 'Meta',
            'external_account_id' => 'act_99',
            'credentials' => [
                'system_user_token' => 'system-token-value-wxyz',
                'capi_token' => 'capi-token-value-fail',
                'pixel_id' => '555',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['credentials.capi_token']);

        $this->assertSame(0, AdPlatformAccount::query()->count());
    }

    #[Test]
    public function meta_account_masks_secrets_and_blank_rotation_keeps_the_stored_token(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'act_99')) {
                return Http::response([
                    'currency' => 'EUR',
                    'timezone_name' => 'Europe/Madrid',
                    'account_status' => 1,
                ]);
            }

            return Http::response(['id' => '555', 'name' => 'Pixel']);
        });

        $this->postJson('/api/ad-platform-accounts', [
            'provider' => 'google_ads',
            'name' => 'Nope',
            'external_account_id' => '1',
            'credentials' => ['refresh_token' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrors(['provider']);

        $created = $this->postJson('/api/ad-platform-accounts', [
            'provider' => 'meta',
            'name' => 'Meta',
            'external_account_id' => 'act_99',
            'credentials' => [
                'system_user_token' => 'system-token-value-wxyz',
                'capi_token' => 'capi-token-value-mnop',
                'pixel_id' => '555',
            ],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.credentials.system_user_token.masked', '••••••wxyz')
            ->assertJsonPath('data.credentials.capi_token.masked', '••••••mnop')
            ->assertJsonPath('data.credentials.pixel_id.masked', '555')
            ->assertJsonPath('data.currency', 'EUR');

        $this->assertStringNotContainsString('system-token-value-wxyz', (string) $created->getContent());

        $id = (int) $created->json('data.id');

        $this->putJson('/api/ad-platform-accounts/'.$id, [
            'name' => 'Meta renamed',
            'credentials' => [
                'system_user_token' => '',
                'capi_token' => '   ',
            ],
        ])->assertOk()->assertJsonPath('data.name', 'Meta renamed');

        $this->assertSame('system-token-value-wxyz', AdPlatformAccount::query()->findOrFail($id)->credentials['system_user_token']);

        $this->putJson('/api/ad-platform-accounts/'.$id, [
            'credentials' => [
                'capi_token' => 'capi-token-value-rotated',
            ],
        ])->assertOk()->assertJsonPath('data.credentials.capi_token.masked', '••••••ated');

        $rotated = Activity::query()->where('description', 'ad_platform_account.rotated')->first();
        $this->assertNotNull($rotated);
        $this->assertSame('••••••wxyz', $rotated->properties['masked']);
        $encoded = (string) json_encode($rotated->properties);
        $this->assertStringNotContainsString('capi-token-value-rotated', $encoded);
        $this->assertStringNotContainsString('system-token-value-wxyz', $encoded);
        $this->assertSame('capi-token-value-rotated', AdPlatformAccount::query()->findOrFail($id)->credentials['capi_token']);
    }

    #[Test]
    public function unreadable_credentials_do_not_500_and_archive_skips_eligible(): void
    {
        $account = AdPlatformAccount::factory()->create([
            'credentials' => ['refresh_token' => 'test-token'],
        ]);

        DB::table('ad_platform_accounts')
            ->where('id', $account->id)
            ->update(['credentials' => 'not-valid-laravel-ciphertext']);

        $this->getJson('/api/ad-platform-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.credentials_unreadable', true)
            ->assertJsonPath('data.0.credentials', []);

        $inactive = AdPlatformAccount::factory()->create([
            'is_active' => false,
            'external_account_id' => '222',
        ]);

        $this->deleteJson('/api/ad-platform-accounts/'.$account->id)->assertOk();
        $this->assertNotNull($account->fresh()->archived_at);

        $readable = AdPlatformAccount::factory()->create([
            'external_account_id' => '444',
            'credentials' => ['refresh_token' => 'archive-token-qrst'],
        ]);
        $this->postJson('/api/ad-platform-accounts/'.$readable->id.'/archive')->assertOk();

        $removed = Activity::query()->where('description', 'ad_platform_account.removed')->latest('id')->first();
        $this->assertNotNull($removed);
        $this->assertSame('••••••qrst', $removed->properties['masked']);
        $this->assertStringNotContainsString('archive-token-qrst', (string) json_encode($removed->properties));

        $this->assertNull(AdPlatformAccount::query()->eligible()->find($account->id));
        $this->assertNull(AdPlatformAccount::query()->eligible()->find($inactive->id));

        $live = AdPlatformAccount::factory()->create(['external_account_id' => '333']);
        $this->assertNotNull(AdPlatformAccount::query()->eligible()->find($live->id));

        $this->postJson('/api/ad-platform-accounts/'.$account->id.'/verify')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account']);
    }

    #[Test]
    public function verify_failure_records_a_system_event_and_keeps_the_row(): void
    {
        $account = AdPlatformAccount::factory()->create([
            'external_account_id' => '1234567890',
            'credentials' => ['refresh_token' => self::REFRESH_TOKEN],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29-access']),
            'https://googleads.googleapis.com/*' => Http::response(['error' => 'denied'], 400),
        ]);

        $this->postJson('/api/ad-platform-accounts/'.$account->id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.last_error', __('errors.advertising.verify_failed'));

        $this->assertNotNull($account->fresh());

        $event = SystemEvent::query()->where('event', 'ads.account.verify_failed')->first();
        $this->assertNotNull($event);
        $this->assertSame(AdPlatformException::class, $event->payload['error_class'] ?? null);
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) json_encode($event->payload));
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response([
                    'access_token' => 'ya29-access',
                    'refresh_token' => self::REFRESH_TOKEN,
                ]);
            }

            if (str_contains($url, 'listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/1234567890']]);
            }

            if (str_contains($url, 'googleAds:searchStream')) {
                return Http::response([[
                    'results' => [[
                        'customer' => [
                            'currencyCode' => 'EUR',
                            'timeZone' => 'Europe/Madrid',
                        ],
                    ]],
                ]]);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
    }

    private function selectionFrom(?string $location): string
    {
        $query = [];
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return (string) ($query['selection'] ?? '');
    }

    private function sentCount(string $needle): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $needle))
            ->count();
    }
}
