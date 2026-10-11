<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\AdCampaign;
use App\Models\AdPlatformAccount;
use App\Models\AdSpendDaily;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\MarketingTouch;
use App\Models\Site;
use App\Support\Advertising\AdPlatform;
use App\Support\Reports\PaidAcquisitionReport;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Database\Factories\EmployeeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaidAcquisitionReportTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->siteA = Site::factory()->create([
            'name' => 'Alpha',
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $this->siteB = Site::factory()->create([
            'name' => 'Beta',
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
        ]);

        $google = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::GoogleAds,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $meta = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::Meta,
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
        ]);

        $mappedGoogle = AdCampaign::factory()->create([
            'ad_platform_account_id' => $google->id,
            'name' => 'Alpha search',
            'external_id' => 'camp-a',
            'site_id' => $this->siteA->id,
        ]);
        $unmapped = AdCampaign::factory()->create([
            'ad_platform_account_id' => $google->id,
            'name' => 'Unmapped search',
            'external_id' => 'camp-u',
            'site_id' => null,
        ]);
        $mappedMeta = AdCampaign::factory()->create([
            'ad_platform_account_id' => $meta->id,
            'name' => 'Beta social',
            'external_id' => 'camp-b',
            'site_id' => $this->siteB->id,
        ]);

        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $mappedGoogle->id,
            'spend_date' => '2026-05-10',
            'cost' => '100.00',
            'currency' => 'EUR',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $unmapped->id,
            'spend_date' => '2026-05-11',
            'cost' => '25.00',
            'currency' => 'EUR',
        ]);
        AdSpendDaily::factory()->create([
            'ad_campaign_id' => $mappedMeta->id,
            'spend_date' => '2026-05-12',
            'cost' => '40.00',
            'currency' => 'GBP',
        ]);

        $paid = $this->makeDeal($this->siteA, '2026-05-20 12:00:00');
        $mid = $this->makeDeal($this->siteA, '2026-05-20 12:00:00');
        $old = $this->makeDeal($this->siteA, '2026-05-20 12:00:00');
        $beta = $this->makeDeal($this->siteB, '2026-05-18 12:00:00');

        $this->touch($paid, AdPlatform::GoogleAds, '2026-05-10 12:00:00', 'camp-a');
        $this->touch($mid, AdPlatform::GoogleAds, '2026-04-01 12:00:00', 'other');
        $this->touch($old, AdPlatform::GoogleAds, '2026-01-20 12:00:00', 'old');
        $this->touch($beta, AdPlatform::Meta, '2026-05-01 12:00:00', 'camp-b');

        $eurContract = Contract::factory()->create([
            'contact_id' => $paid->contact_id,
            'deal_id' => $paid->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'signed_at' => '2026-05-25 12:00:00',
        ]);
        Charge::factory()->create([
            'contract_id' => $eurContract->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '250.00',
            'amount' => '250.00',
            'currency' => 'EUR',
        ]);
        Charge::factory()->create([
            'contract_id' => $eurContract->id,
            'charge_type' => ChargeType::Deposit,
            'net_amount' => '999.00',
            'amount' => '999.00',
            'currency' => 'EUR',
        ]);

        $mismatch = Contract::factory()->create([
            'contact_id' => $beta->contact_id,
            'deal_id' => $beta->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'signed_at' => '2026-05-26 12:00:00',
        ]);
        Charge::factory()->create([
            'contract_id' => $mismatch->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '80.00',
            'amount' => '80.00',
            'currency' => 'EUR',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_totals_stay_inside_one_currency_and_unmapped_spend_is_its_own_row(): void
    {
        $result = $this->report(90);

        $alpha = $this->row($result, $this->siteA->id, 'google_ads');
        $unmapped = $this->row($result, null, 'google_ads');
        $beta = $this->row($result, $this->siteB->id, 'meta');

        $this->assertSame('100.00', $alpha['spend']);
        $this->assertSame('EUR', $alpha['currency']);
        $this->assertSame(2, $alpha['paid_leads']);
        $this->assertSame(1, $alpha['paid_move_ins']);
        $this->assertSame('50.00', $alpha['cost_per_lead']);
        $this->assertSame('2.50', $alpha['roas']);
        $this->assertNull($alpha['roas_reason']);

        $this->assertSame('unmapped', $unmapped['row_kind']);
        $this->assertSame('25.00', $unmapped['spend']);
        $this->assertSame(0, $unmapped['paid_leads']);
        $this->assertNull($unmapped['cost_per_lead']);
        $this->assertSame('site', $alpha['row_kind']);

        $this->assertSame('40.00', $beta['spend']);
        $this->assertSame('GBP', $beta['currency']);
        $this->assertNull($beta['roas']);
        $this->assertSame('currency_mismatch', $beta['roas_reason']);

        $sums = [];
        foreach ($result->rows as $row) {
            $currency = (string) $row['currency'];
            $sums[$currency] = bcadd($sums[$currency] ?? '0.00', (string) $row['spend'], 2);
        }
        $this->assertSame('125.00', $sums['EUR']);
        $this->assertSame('40.00', $sums['GBP']);
        $this->assertArrayNotHasKey('mixed', $sums);

        $raw = AdSpendDaily::query()
            ->selectRaw('currency, sum(cost) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');
        $this->assertSame('125.00', number_format((float) $raw['EUR'], 2, '.', ''));
        $this->assertSame('40.00', number_format((float) $raw['GBP'], 2, '.', ''));

        $eurKpi = collect($result->meta['kpis'])->firstWhere('currency', 'EUR');
        $this->assertSame('100.00', $eurKpi['spend']);
        $this->assertSame(2, $eurKpi['paid_leads']);

        $campaigns = collect($result->meta['campaigns']);
        $this->assertSame('mapped', $campaigns->firstWhere('campaign', 'Alpha search')['mapping_status']);
        $this->assertSame(1, $campaigns->firstWhere('campaign', 'Alpha search')['paid_leads']);
        $this->assertSame('unmapped', $campaigns->firstWhere('campaign', 'Unmapped search')['mapping_status']);
        $this->assertSame(1, $campaigns->filter(static fn (array $row): bool => $row['mapping_status'] === 'unmapped')->count());
    }

    public function test_window_changes_counts_without_writes_and_old_touch_stays_organic(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $at30 = $this->report(30);
        $at60 = $this->report(60);
        $at90 = $this->report(90);

        foreach (DB::getQueryLog() as $entry) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(insert|update|delete)\b/i',
                (string) $entry['query'],
            );
        }

        $this->assertSame(1, $this->row($at30, $this->siteA->id, 'google_ads')['paid_leads']);
        $this->assertSame(2, $this->row($at60, $this->siteA->id, 'google_ads')['paid_leads']);
        $this->assertSame(2, $this->row($at90, $this->siteA->id, 'google_ads')['paid_leads']);
    }

    public function test_site_subset_omits_unmapped_spend(): void
    {
        $result = (new PaidAcquisitionReport)->runBounded(new ReportFilters(
            siteIds: [$this->siteA->id],
            from: '2026-05-01',
            to: '2026-05-31',
            attributionWindow: 90,
        ));

        $kinds = array_column($result->rows, 'row_kind');
        $this->assertNotContains('unmapped', $kinds);
        $this->assertSame('100.00', $this->row($result, $this->siteA->id, 'google_ads')['spend']);
        $this->assertNull($this->findRow($result, $this->siteB->id, 'meta'));
    }

    public function test_http_requires_advertising_view(): void
    {
        $owner = Employee::factory()->manager()->create();
        Sanctum::actingAs($owner);

        $this->getJson('/api/reports/paid-acquisition?from=2026-05-01&to=2026-05-31&attribution_window=90')
            ->assertOk()
            ->assertJsonPath('data.rows.0.spend', '100.00');

        $accountant = Employee::factory()->withoutRoleGrant()->create();
        EmployeeFactory::grantCompanyRole($accountant, 'accountant');
        Sanctum::actingAs($accountant);

        $this->getJson('/api/reports/paid-acquisition?from=2026-05-01&to=2026-05-31')
            ->assertForbidden();
    }

    private function report(int $window): ReportResult
    {
        return (new PaidAcquisitionReport)->runBounded(new ReportFilters(
            from: '2026-05-01',
            to: '2026-05-31',
            attributionWindow: $window,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReportResult $result, ?int $siteId, string $platform): array
    {
        $row = $this->findRow($result, $siteId, $platform);
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(ReportResult $result, ?int $siteId, string $platform): ?array
    {
        foreach ($result->rows as $row) {
            if ($row['site_id'] === $siteId && $row['platform'] === $platform) {
                return $row;
            }
        }

        return null;
    }

    private function makeDeal(Site $site, string $createdAt): Deal
    {
        $deal = Deal::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'site_id' => $site->id,
            'status' => DealStatus::New,
        ]);
        $deal->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $deal->fresh();
    }

    private function touch(Deal $deal, AdPlatform $platform, string $occurredAt, string $utm): void
    {
        MarketingTouch::factory()->create([
            'contact_id' => $deal->contact_id,
            'site_id' => $deal->site_id,
            'platform' => $platform,
            'occurred_at' => $occurredAt,
            'utm_campaign' => $utm,
            'click_ids' => ['gclid' => 'secret-click'],
            'landing_url' => 'https://example.test/secret',
        ]);
    }
}
