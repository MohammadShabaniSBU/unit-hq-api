<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Models\Activity;
use App\Models\AdCampaign;
use App\Models\AdPlatformAccount;
use App\Models\AdSpendDaily;
use App\Models\MarketingTouch;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\AdPlatform;
use App\Support\Auth\Permission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AdSyncTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function campaign_sync_updates_catalogue_fields_and_keeps_mapping(): void
    {
        $account = $this->account();
        $site = Site::factory()->create();
        $seen = Carbon::parse('2026-01-01 08:00:00');
        $mapped = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => '11',
            'name' => 'Old name',
            'status' => AdCampaignStatus::Enabled,
            'site_id' => $site->id,
            'unit_class_id' => UnitClass::factory()->create()->id,
            'utm_campaign_hint' => 'spring',
            'first_seen_at' => $seen,
        ]);
        $kept = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => 'keep',
            'name' => 'Still here',
            'status' => AdCampaignStatus::Enabled,
        ]);

        $this->fakeGoogle(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'metrics.cost_micros')) {
                return Http::response(['error' => 'spend not expected'], 500);
            }

            return Http::response([[
                'results' => [[
                    'campaign' => [
                        'id' => '11',
                        'name' => 'New name',
                        'status' => 'REMOVED',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                ]],
            ]]);
        });

        $this->assertSame(0, Artisan::call('ads:sync-campaigns'));

        $mapped->refresh();
        $this->assertSame('New name', $mapped->name);
        $this->assertSame(AdCampaignStatus::Removed, $mapped->status);
        $this->assertSame('search', $mapped->channel_type);
        $this->assertSame($site->id, $mapped->site_id);
        $this->assertNotNull($mapped->unit_class_id);
        $this->assertSame('spring', $mapped->utm_campaign_hint);
        $this->assertSame($seen->toDateTimeString(), $mapped->first_seen_at->toDateTimeString());
        $this->assertNotNull(AdCampaign::query()->find($kept->id));
        $this->assertSame('Still here', $kept->fresh()->name);
        $this->assertNull($account->fresh()->last_error);
    }

    #[Test]
    public function spend_sync_is_idempotent_rounds_micros_and_leaves_older_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00', 'UTC'));

        $account = $this->account();
        $campaign = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => '11',
        ]);
        $old = AdSpendDaily::factory()->create([
            'ad_campaign_id' => $campaign->id,
            'spend_date' => '2026-09-01',
            'cost' => '9.99',
            'currency' => 'EUR',
            'synced_at' => '2026-09-02 00:00:00',
        ]);
        $syncedAt = $old->synced_at->toDateTimeString();
        $pass = 0;

        $this->fakeGoogle(function (Request $request) use (&$pass) {
            $body = (string) $request->body();

            if (str_contains($body, 'currency_code FROM customer')) {
                return $this->currency('EUR');
            }

            if (! str_contains($body, 'metrics.cost_micros')) {
                return Http::response([['results' => []]]);
            }

            $pass++;
            $rows = $pass === 1
                ? [
                    $this->spendResult('11', '2026-10-10', '1234567'),
                    $this->spendResult('11', '2026-10-11', '1235000'),
                    $this->spendResult('99', '2026-10-11', '1000000'),
                ]
                : [
                    $this->spendResult('11', '2026-10-11', '2000000'),
                ];

            return Http::response([['results' => $rows]]);
        });

        $this->assertSame(0, Artisan::call('ads:sync-spend', ['--days' => 7]));

        $this->assertSame(3, AdSpendDaily::query()->count());
        $this->assertSame('1.23', $this->cost($campaign->id, '2026-10-10'));
        $this->assertSame('1.24', $this->cost($campaign->id, '2026-10-11'));
        $this->assertSame('9.99', $old->fresh()->cost);
        $this->assertSame($syncedAt, $old->fresh()->synced_at->toDateTimeString());

        $this->assertSame(0, Artisan::call('ads:sync-spend', ['--days' => 7]));

        $this->assertSame(3, AdSpendDaily::query()->count());
        $this->assertSame('1.23', $this->cost($campaign->id, '2026-10-10'));
        $this->assertSame('2.00', $this->cost($campaign->id, '2026-10-11'));
        $this->assertSame('9.99', $old->fresh()->cost);
        $this->assertNull($account->fresh()->last_error);
    }

    #[Test]
    public function currency_mismatch_writes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00', 'UTC'));

        $account = $this->account();
        $campaign = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => '11',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $campaign->id,
            'spend_date' => '2026-10-11',
            'cost' => '4.00',
            'currency' => 'EUR',
        ]);

        $this->fakeGoogle(function (Request $request) {
            if (str_contains((string) $request->body(), 'currency_code FROM customer')) {
                return $this->currency('USD');
            }

            return Http::response([['results' => []]]);
        });

        $this->assertSame(0, Artisan::call('ads:sync-spend'));

        $this->assertSame(1, AdSpendDaily::query()->count());
        $this->assertSame('4.00', $this->cost($campaign->id, '2026-10-11'));
        $this->assertStringContainsString('currency_mismatch', (string) $account->fresh()->last_error);
        $this->assertTrue($this->eventFor('ads.sync.failed', $account->id));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'metrics.cost_micros'));
    }

    #[Test]
    public function one_failing_account_does_not_stop_the_others(): void
    {
        $bad = $this->account(['external_account_id' => '1111111111']);
        $good = $this->account(['external_account_id' => '2222222222']);

        $this->fakeGoogle(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/customers/1111111111/')) {
                return Http::response(['error' => 'down'], 500);
            }

            return Http::response([[
                'results' => [[
                    'campaign' => [
                        'id' => '11',
                        'name' => 'Brand',
                        'status' => 'ENABLED',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                ]],
            ]]);
        });

        $this->assertSame(0, Artisan::call('ads:sync-campaigns'));

        $this->assertNotNull($bad->fresh()->last_error);
        $this->assertSame(0, AdCampaign::query()->where('ad_platform_account_id', $bad->id)->count());
        $this->assertSame('Brand', AdCampaign::query()->where('ad_platform_account_id', $good->id)->value('name'));
        $this->assertTrue($this->eventFor('ads.sync.failed', $bad->id));
        $this->assertTrue($this->eventFor('ads.sync.committed', $good->id));
    }

    #[Test]
    public function archived_and_inactive_accounts_are_skipped(): void
    {
        $archived = $this->account([
            'external_account_id' => '3333333333',
            'archived_at' => now(),
        ]);
        $inactive = $this->account([
            'external_account_id' => '4444444444',
            'is_active' => false,
        ]);
        AdCampaign::factory()->create([
            'ad_platform_account_id' => $archived->id,
            'external_id' => '33',
            'name' => 'Archived name',
        ]);
        AdCampaign::factory()->create([
            'ad_platform_account_id' => $inactive->id,
            'external_id' => '44',
            'name' => 'Inactive name',
        ]);

        $this->fakeGoogle(fn () => Http::response(['error' => 'should not be called'], 500));

        $this->assertSame(0, Artisan::call('ads:sync-campaigns'));

        $this->assertSame('Archived name', AdCampaign::query()->where('external_id', '33')->value('name'));
        $this->assertSame('Inactive name', AdCampaign::query()->where('external_id', '44')->value('name'));
        $this->assertNull($archived->fresh()->last_error);
        $this->assertNull($inactive->fresh()->last_error);
        $this->assertSame(0, SystemEvent::query()->where('event', 'like', 'ads.sync.%')->count());
    }

    #[Test]
    public function touch_matches_hint_or_same_platform_campaign_id(): void
    {
        $google = $this->account(['external_account_id' => '2222222222']);
        $meta = $this->account([
            'provider' => AdPlatform::Meta,
            'external_account_id' => '99',
            'credentials' => ['system_user_token' => 'system', 'capi_token' => 'capi', 'pixel_id' => '555'],
        ]);
        $byHint = AdCampaign::factory()->create([
            'ad_platform_account_id' => $meta->id,
            'external_id' => '1',
            'utm_campaign_hint' => 'spring',
        ]);
        $byGoogleId = AdCampaign::factory()->create([
            'ad_platform_account_id' => $google->id,
            'external_id' => '11',
        ]);
        $byMetaId = AdCampaign::factory()->create([
            'ad_platform_account_id' => $meta->id,
            'external_id' => '11',
        ]);

        $hintTouch = MarketingTouch::factory()->create([
            'platform' => AdPlatform::GoogleAds,
            'utm_campaign' => 'spring',
        ]);
        $googleTouch = MarketingTouch::factory()->create([
            'platform' => AdPlatform::GoogleAds,
            'utm_campaign' => '11',
        ]);
        $metaTouch = MarketingTouch::factory()->create([
            'platform' => AdPlatform::Meta,
            'utm_campaign' => '11',
        ]);
        $empty = MarketingTouch::factory()->create([
            'platform' => AdPlatform::GoogleAds,
            'utm_campaign' => null,
        ]);

        $this->assertSame([$byHint->id], $this->matchIds($hintTouch));
        $this->assertSame([$byGoogleId->id], $this->matchIds($googleTouch));
        $this->assertSame([$byMetaId->id], $this->matchIds($metaTouch));
        $this->assertSame([], $this->matchIds($empty));
    }

    #[Test]
    public function list_suggests_one_site_sums_spend_per_currency_and_hides_unmapped_from_site_staff(): void
    {
        $malaga = Site::factory()->create(['name' => 'Málaga', 'code' => 'SITE-MAL']);
        $madrid = Site::factory()->create(['name' => 'Madrid', 'code' => 'SITE-MAD']);
        Site::factory()->create(['name' => 'Barcelona', 'code' => 'SITE-BCN']);
        $account = $this->account();
        $suggested = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => 'malaga',
            'name' => 'Malaga Search',
            'site_id' => $malaga->id,
        ]);
        $ambiguous = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => 'both',
            'name' => 'Madrid Barcelona Ads',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $suggested->id,
            'spend_date' => '2026-10-01',
            'cost' => '1.00',
            'impressions' => 10,
            'clicks' => 1,
            'currency' => 'EUR',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $suggested->id,
            'spend_date' => '2026-10-02',
            'cost' => '2.50',
            'impressions' => 5,
            'clicks' => 2,
            'currency' => 'EUR',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $ambiguous->id,
            'spend_date' => '2026-10-02',
            'cost' => '9.00',
            'impressions' => 3,
            'clicks' => 1,
            'currency' => 'USD',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $suggested->id,
            'spend_date' => '2026-09-01',
            'cost' => '8.00',
            'currency' => 'EUR',
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingView));

        $plain = $this->getJson('/api/ad-campaigns');
        $plain->assertOk();
        $this->assertArrayNotHasKey('spend', $plain->json('data.0'));

        $listed = $this->getJson('/api/ad-campaigns?from=2026-10-01&to=2026-10-02&per_page=50');
        $listed->assertOk();
        $rows = collect($listed->json('data'))->keyBy('external_id');

        $this->assertSame($malaga->id, $rows['malaga']['suggested_site_id']);
        $this->assertNull($rows['both']['suggested_site_id']);
        $this->assertSame([
            ['currency' => 'EUR', 'cost' => '3.50', 'impressions' => 15, 'clicks' => 3],
        ], $rows['malaga']['spend']);
        $this->assertSame([
            ['currency' => 'USD', 'cost' => '9.00', 'impressions' => 3, 'clicks' => 1],
        ], $rows['both']['spend']);

        $unmapped = $this->getJson('/api/ad-campaigns?mapped=false');
        $unmapped->assertOk();
        $this->assertSame(['both'], collect($unmapped->json('data'))->pluck('external_id')->all());

        Sanctum::actingAs($this->employeeWithSiteScopedPermission(Permission::AdvertisingView, $malaga));
        $scoped = $this->getJson('/api/ad-campaigns?per_page=50');
        $scoped->assertOk();
        $this->assertSame(['malaga'], collect($scoped->json('data'))->pluck('external_id')->all());
        $this->assertNotSame($madrid->id, $malaga->id);
        $this->assertNotSame($ambiguous->id, $suggested->id);
    }

    #[Test]
    public function mapping_requires_a_unit_class_at_the_site_and_site_staff_cannot_map_unmapped(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $account = $this->account();
        $campaign = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => '11',
            'utm_campaign_hint' => 'keep-hint',
        ]);
        $second = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => '12',
        ]);
        $unitClass = UnitClass::factory()->create();

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingManage));

        $this->putJson('/api/ad-campaigns/'.$campaign->id.'/mapping', [
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_class_id']);
        $this->assertNull($campaign->fresh()->site_id);
        $this->assertSame(0, Activity::query()->where('description', 'ads.campaign.mapped')->count());

        UnitClassRate::query()->create([
            'unit_class_id' => $unitClass->id,
            'site_id' => $site->id,
        ]);

        $mapped = $this->putJson('/api/ad-campaigns/'.$campaign->id.'/mapping', [
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'utm_campaign_hint' => 'spring',
        ]);
        $mapped->assertOk()
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.unit_class_id', $unitClass->id)
            ->assertJsonPath('data.utm_campaign_hint', 'spring');
        $this->assertSame(1, Activity::query()->where('description', 'ads.campaign.mapped')->count());

        $this->postJson('/api/ad-campaigns/mapping/bulk', [
            'ids' => [$campaign->id, $second->id, 999999],
            'site_id' => $site->id,
        ])->assertNotFound();
        $this->assertNull($second->fresh()->site_id);

        $this->postJson('/api/ad-campaigns/mapping/bulk', [
            'ids' => [$campaign->id, $second->id],
            'site_id' => $site->id,
        ])->assertOk();

        $campaign->refresh();
        $second->refresh();
        $this->assertSame($site->id, $campaign->site_id);
        $this->assertSame($unitClass->id, $campaign->unit_class_id);
        $this->assertSame('spring', $campaign->utm_campaign_hint);
        $this->assertSame($site->id, $second->site_id);
        $this->assertSame(3, Activity::query()->where('description', 'ads.campaign.mapped')->count());

        Sanctum::actingAs($this->employeeWithSiteScopedPermission(Permission::AdvertisingManage, $other));
        $this->putJson('/api/ad-campaigns/'.$second->id.'/mapping', [
            'site_id' => $other->id,
        ])->assertNotFound();
        $this->assertSame($site->id, $second->fresh()->site_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): AdPlatformAccount
    {
        return AdPlatformAccount::factory()->create(array_merge([
            'provider' => AdPlatform::GoogleAds,
            'external_account_id' => '2222222222',
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'credentials' => ['refresh_token' => 'refresh-token'],
            'is_active' => true,
            'archived_at' => null,
        ], $overrides));
    }

    private function fakeGoogle(callable $search): void
    {
        Http::fake(function (Request $request) use ($search) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29-access']);
            }

            if (str_contains($request->url(), 'googleAds:searchStream')) {
                return $search($request);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
    }

    private function currency(string $code): mixed
    {
        return Http::response([[
            'results' => [[
                'customer' => ['currencyCode' => $code],
            ]],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function spendResult(string $id, string $date, string $micros): array
    {
        return [
            'campaign' => ['id' => $id],
            'segments' => ['date' => $date],
            'metrics' => [
                'costMicros' => $micros,
                'impressions' => '4',
                'clicks' => '1',
            ],
        ];
    }

    private function cost(int $campaignId, string $date): string
    {
        $row = AdSpendDaily::query()
            ->where('ad_campaign_id', $campaignId)
            ->whereDate('spend_date', $date)
            ->first();

        return $row === null ? '' : (string) $row->cost;
    }

    private function eventFor(string $event, int $accountId): bool
    {
        return SystemEvent::query()
            ->where('event', $event)
            ->get()
            ->contains(fn (SystemEvent $row): bool => (int) ($row->payload['account_id'] ?? 0) === $accountId);
    }

    /**
     * @return list<int>
     */
    private function matchIds(MarketingTouch $touch): array
    {
        return AdCampaign::query()
            ->matchingTouch($touch)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
