<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\HoldType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DelinquencyBoardTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $siteEur;

    private Site $siteGbp;

    private DelinquencyPolicy $policy;

    private UnitClass $unitClass;

    private int $priceEur;

    private int $priceGbp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $es = Country::factory()->create(['code' => 'ES']);
        $gb = Country::factory()->create(['code' => 'GB']);

        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'board-policy']);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 3,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 10,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 2,
        ]);

        $this->siteEur = Site::factory()->create([
            'country_id' => $es->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $this->policy->id,
        ]);
        $this->siteGbp = Site::factory()->create([
            'country_id' => $gb->id,
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
            'delinquency_policy_id' => $this->policy->id,
        ]);

        $this->unitClass = UnitClass::factory()->create();
        [, $pEur] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->siteEur->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        [, $pGbp] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->siteGbp->id,
            $this->employee->id,
            ['amount' => '80.00', 'currency' => 'GBP', 'effective_from' => '2026-01-01'],
        );
        $this->priceEur = $pEur->id;
        $this->priceGbp = $pGbp->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_aggregate_endpoint_bounded_queries(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->seedOpenCase($this->siteEur, $this->priceEur, 'EUR', '2026-08-10');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->seedOpenCase($this->siteGbp, $this->priceGbp, 'GBP', '2026-08-01');
        }

        // One paused, one overlocked among EUR.
        $paused = Delinquency::query()->whereNull('cured_on')->orderBy('id')->firstOrFail();
        DelinquencyLifecycle::pause($paused, 'dispute', $this->employee);

        $overlockCase = Delinquency::query()->whereNull('cured_on')->where('id', '!=', $paused->id)->orderBy('id')->firstOrFail();
        $unitId = $overlockCase->contract->unitItem->item_id;
        UnitHold::query()->create([
            'unit_id' => $unitId,
            'hold_type' => HoldType::Overlock,
            'reservation_id' => null,
            'starts_on' => '2026-08-20',
            'ends_on' => null,
            'released_at' => null,
            'reason' => 'delinquency:'.$overlockCase->id,
            'created_by' => $this->employee->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/delinquencies?per_page=50');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonPath('meta.open_count', 50);
        $response->assertJsonPath('meta.total', 50);
        $response->assertJsonPath('meta.overlocked_count', 1);

        $byCurrency = collect($response->json('meta.overdue_by_currency'));
        $this->assertTrue($byCurrency->contains(fn ($row) => $row['currency'] === 'EUR'));
        $this->assertTrue($byCurrency->contains(fn ($row) => $row['currency'] === 'GBP'));
        // Never a single summed total across currencies.
        $this->assertCount(2, $byCurrency);

        // Bounded: eager loads + chip queries, not one-per-case explosion.
        $this->assertLessThanOrEqual(40, $queryCount, "Expected bounded queries, got {$queryCount}");

        $pausedFilter = $this->getJson('/api/delinquencies?paused=1');
        $pausedFilter->assertOk();
        $pausedFilter->assertJsonPath('meta.total', 1);

        $overlockedFilter = $this->getJson('/api/delinquencies?overlocked=1');
        $overlockedFilter->assertOk();
        $overlockedFilter->assertJsonPath('meta.total', 1);

        $siteFilter = $this->getJson('/api/delinquencies?site_id='.$this->siteGbp->id);
        $siteFilter->assertOk();
        $siteFilter->assertJsonPath('meta.total', 10);

        $bucket = $this->getJson('/api/delinquencies?days_bucket=15-30');
        $bucket->assertOk();
        // GBP cases due 2026-08-01 → 19 days overdue on 2026-08-20.
        $this->assertGreaterThanOrEqual(10, $bucket->json('meta.total'));
    }

    private function seedOpenCase(Site $site, int $priceId, string $currency, string $dueDate): Delinquency
    {
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => $currency,
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $priceId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => $currency,
            'due_date' => $dueDate,
        ]);

        $contract = $contract->fresh(['unitItem.item.site', 'charges.allocations']) ?? $contract;

        return DelinquencyLifecycle::openOrFail($contract);
    }
}
