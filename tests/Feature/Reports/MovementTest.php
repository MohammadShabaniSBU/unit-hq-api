<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Enums\DepositPayoutStatus;
use App\Enums\DepositSettlementOutcome;
use App\Enums\TransferPricingMode;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractTransfer;
use App\Models\Country;
use App\Models\DepositSettlement;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Reports\MovementReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class MovementTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $smallClass;

    private UnitClass $largeClass;

    private int $smallPriceId;

    private int $largePriceId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00', 'Europe/Madrid'));

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

        $this->smallClass = UnitClass::factory()->create([
            'code' => 'S5',
            'size' => '5.00',
        ]);
        [, $smallPrice] = $this->createUnitClassCataloguePrice(
            $this->smallClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->smallPriceId = (int) $smallPrice->id;

        $this->largeClass = UnitClass::factory()->create([
            'code' => 'S15',
            'size' => '15.00',
        ]);
        [, $largePrice] = $this->createUnitClassCataloguePrice(
            $this->largeClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '250.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->largePriceId = (int) $largePrice->id;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_transfers_cancel_identity(): void
    {
        // Occupied before period: 2 units (carry-in)
        $carryA = $this->openOccupancy($this->makeUnit('C-1', $this->smallClass), '2026-01-01');
        $carryB = $this->openOccupancy($this->makeUnit('C-2', $this->smallClass), '2026-02-01');

        // Move-ins in Q2: 3
        $this->openOccupancy($this->makeUnit('IN-1', $this->smallClass), '2026-04-10');
        $this->openOccupancy($this->makeUnit('IN-2', $this->smallClass), '2026-05-01');
        $this->openOccupancy($this->makeUnit('IN-3', $this->smallClass), '2026-06-01');

        // Move-outs in Q2: 2 (vacated)
        $this->closeOccupancy($carryA, '2026-04-15', ContractEndedReason::Vacated);
        $this->closeOccupancy($carryB, '2026-05-20', ContractEndedReason::Vacated);

        // Transfer mid-period: closes origin / opens dest — must cancel from identity
        $fromUnit = $this->makeUnit('T-FROM', $this->smallClass);
        $toUnit = $this->makeUnit('T-TO', $this->largeClass);
        $this->seedTransfer($fromUnit, $toUnit, '2026-03-01', '2026-05-10', '100.00', '250.00');

        $result = (new MovementReport)->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            from: '2026-04-01',
            to: '2026-06-30',
        ));

        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame(3, $row['move_ins']);
        $this->assertSame(2, $row['move_outs_vacated'] + $row['move_outs_non_payment']);
        $this->assertSame(1, $row['transfers']);
        $this->assertSame(3 - 2, $row['net_units']);
        $this->assertSame($row['net_units'], $row['delta_occupied']);

        $identity = $result->meta['identity'];
        $this->assertSame(
            $identity['move_ins'] - $identity['move_outs'],
            $identity['delta_occupied'],
        );
        $this->assertSame(1, $identity['transfers']);

        $this->getJson('/api/reports/movement?from=2026-04-01&to=2026-06-30&site_ids[]='.$this->site->id)
            ->assertOk()
            ->assertJsonPath('data.rows.0.move_ins', 3);
    }

    public function test_involuntary_split_and_rate_delta(): void
    {
        // Two leavers with known tenure and reasons
        $volUnit = $this->makeUnit('V-1', $this->smallClass);
        $volOcc = $this->openOccupancy($volUnit, '2026-01-01'); // 120 days to Apr 30? use May 1 = 120 days from Jan 1
        $this->closeOccupancy($volOcc, '2026-05-01', ContractEndedReason::Vacated);
        DepositSettlement::query()->create([
            'contract_id' => $volOcc->contract_id,
            'outcome' => DepositSettlementOutcome::Released,
            'deposit_amount' => '100.00',
            'refunded_amount' => '100.00',
            'currency' => 'EUR',
            'payout_status' => DepositPayoutStatus::Pending,
            'created_by' => $this->employee->id,
        ]);

        $invUnit = $this->makeUnit('NP-1', $this->smallClass);
        $invOcc = $this->openOccupancy($invUnit, '2026-03-01');
        $this->closeOccupancy($invOcc, '2026-05-31', ContractEndedReason::NonPayment);
        DepositSettlement::query()->create([
            'contract_id' => $invOcc->contract_id,
            'outcome' => DepositSettlementOutcome::Deducted,
            'deposit_amount' => '100.00',
            'refunded_amount' => '40.00',
            'currency' => 'EUR',
            'payout_status' => DepositPayoutStatus::Pending,
            'created_by' => $this->employee->id,
        ]);

        // Up-class transfer: 100 → 250 = +150.00
        $fromUnit = $this->makeUnit('UP-FROM', $this->smallClass);
        $toUnit = $this->makeUnit('UP-TO', $this->largeClass);
        $this->seedTransfer($fromUnit, $toUnit, '2026-02-01', '2026-05-15', '100.00', '250.00');

        $result = (new MovementReport)->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            from: '2026-04-01',
            to: '2026-06-30',
        ));

        $row = $result->rows[0];
        $this->assertSame(1, $row['move_outs_vacated']);
        $this->assertSame(1, $row['move_outs_non_payment']);
        $this->assertSame(1, $row['transfers']);
        $this->assertSame(1, $row['transfers_up']);
        $this->assertSame(0, $row['transfers_down']);
        $this->assertSame(0, $row['transfers_same']);
        $this->assertSame('150.00', $row['rate_delta']);
        $this->assertSame('150.00', $result->meta['rate_delta_by_currency'][0]['amount']);

        // Tenure: Jan 1→May 1 = 120 days; Mar 1→May 31 = 91 days; avg = 105.5
        $this->assertSame(105.5, $result->meta['avg_tenure_days']);
        $this->assertSame(1, $result->meta['deposit_outcomes']['full_refund']);
        $this->assertSame(1, $result->meta['deposit_outcomes']['deductions']);
        $this->assertSame(1, $result->meta['ended_reason_counts']['vacated']);
        $this->assertSame(1, $result->meta['ended_reason_counts']['non_payment']);
    }

    private function makeUnit(string $number, UnitClass $class): Unit
    {
        return Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $class->id,
            'unit_number' => $number,
        ]);
    }

    private function openOccupancy(Unit $unit, string $startedOn): UnitOccupancy
    {
        $priceId = (int) $unit->unit_class_id === (int) $this->largeClass->id
            ? $this->largePriceId
            : $this->smallPriceId;

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => $startedOn,
            'start_date' => $startedOn,
            'signed_at' => $startedOn.' 10:00:00',
        ]);

        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $priceId,
            'effective_from' => $startedOn,
            'effective_to' => null,
        ]);

        return UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'started_on' => $startedOn,
            'ended_on' => null,
            'ended_reason' => null,
            'created_by' => $this->employee->id,
        ]);
    }

    private function closeOccupancy(
        UnitOccupancy $occupancy,
        string $endedOn,
        ContractEndedReason $reason,
    ): void {
        $occupancy->update([
            'ended_on' => $endedOn,
            'ended_reason' => $reason->value,
        ]);
        Contract::query()->whereKey($occupancy->contract_id)->update([
            'status' => ContractStatus::Ended,
            'move_out_on' => $endedOn,
            'ended_reason' => $reason,
        ]);
        ContractItem::query()
            ->where('contract_id', $occupancy->contract_id)
            ->whereNull('effective_to')
            ->update(['effective_to' => $endedOn]);
    }

    private function seedTransfer(
        Unit $fromUnit,
        Unit $toUnit,
        string $moveIn,
        string $transferDate,
        string $fromAmount,
        string $toAmount,
    ): void {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => $moveIn,
            'start_date' => $moveIn,
            'signed_at' => $moveIn.' 10:00:00',
        ]);

        $fromItem = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $fromUnit->id,
            'price_id' => $this->smallPriceId,
            'effective_from' => $moveIn,
            'effective_to' => $transferDate,
        ]);
        $fromItem->price()->update(['amount' => $fromAmount]);

        $toItem = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $toUnit->id,
            'price_id' => $this->largePriceId,
            'effective_from' => $transferDate,
            'effective_to' => null,
        ]);
        $toItem->price()->update(['amount' => $toAmount]);

        UnitOccupancy::query()->create([
            'unit_id' => $fromUnit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $fromItem->id,
            'started_on' => $moveIn,
            'ended_on' => $transferDate,
            'ended_reason' => ContractEndedReason::TransferredOut->value,
            'created_by' => $this->employee->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $toUnit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $toItem->id,
            'started_on' => $transferDate,
            'ended_on' => null,
            'ended_reason' => null,
            'created_by' => $this->employee->id,
        ]);

        ContractTransfer::query()->create([
            'contract_id' => $contract->id,
            'from_unit_id' => $fromUnit->id,
            'to_unit_id' => $toUnit->id,
            'from_contract_item_id' => $fromItem->id,
            'to_contract_item_id' => $toItem->id,
            'transfer_date' => $transferDate,
            'pricing_mode' => TransferPricingMode::DestinationRate,
            'reason' => 'upsell',
            'created_by' => $this->employee->id,
        ]);
    }
}
