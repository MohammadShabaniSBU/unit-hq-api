<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ContractStatus;
use App\Enums\DepositPayoutStatus;
use App\Enums\DepositSettlementOutcome;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\DepositSettlement;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Reports\DepositLiabilityReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DepositLiabilityTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ties_to_snapshots(): void
    {
        $activeA = $this->makeContract(ContractStatus::Active, '200.00', 'D-1');
        $activeB = $this->makeContract(ContractStatus::NoticeGiven, '150.00', 'D-2');
        // Ended with settlement should not count as held
        $ended = $this->makeContract(ContractStatus::Ended, '300.00', 'D-3');
        DepositSettlement::query()->create([
            'contract_id' => $ended->id,
            'outcome' => DepositSettlementOutcome::Released,
            'deposit_amount' => '300.00',
            'refunded_amount' => '300.00',
            'currency' => 'EUR',
            'payout_status' => DepositPayoutStatus::Pending,
            'created_by' => $this->employee->id,
        ]);

        // Another ended settlement already paid — not pending
        $paidOut = $this->makeContract(ContractStatus::Ended, '80.00', 'D-4');
        DepositSettlement::query()->create([
            'contract_id' => $paidOut->id,
            'outcome' => DepositSettlementOutcome::Released,
            'deposit_amount' => '80.00',
            'refunded_amount' => '80.00',
            'currency' => 'EUR',
            'payout_status' => DepositPayoutStatus::Paid,
            'paid_at' => now(),
            'created_by' => $this->employee->id,
        ]);

        $report = new DepositLiabilityReport;
        $result = $report->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-06-15',
        ));

        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame('Madrid Hub', $row['site']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('350.00', $row['deposits_held']); // 200+150
        $this->assertSame(2, $row['contract_count']);
        $this->assertSame('300.00', $row['pending_payouts']);
        $this->assertSame(1, $row['pending_count']);

        $this->assertSame('350.00', $result->meta['totals_by_currency'][0]['deposits_held']);
        $this->assertSame('300.00', $result->meta['totals_by_currency'][0]['pending_payouts']);

        // Direct snapshot math
        $held = (string) Contract::query()
            ->whereIn('id', [$activeA->id, $activeB->id])
            ->sum('deposit_amount');
        $this->assertSame('350.00', number_format((float) $held, 2, '.', ''));

        $pending = (string) DepositSettlement::query()
            ->where('payout_status', DepositPayoutStatus::Pending->value)
            ->sum('refunded_amount');
        $this->assertSame('300.00', number_format((float) $pending, 2, '.', ''));

        $api = $this->getJson('/api/reports/deposit-liability?as_of=2026-06-15&site_ids[]='.$this->site->id);
        $api->assertOk();
        $api->assertJsonPath('data.rows.0.deposits_held', '350.00');
        $api->assertJsonPath('data.rows.0.pending_payouts', '300.00');
    }

    private function makeContract(ContractStatus $status, string $deposit, string $unitNumber): Contract
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $unitNumber,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => $status,
            'move_in_date' => '2026-01-01',
            'deposit_amount' => $deposit,
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        return $contract;
    }
}
