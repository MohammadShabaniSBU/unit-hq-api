<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Reports\RentRollReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RentRollTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Price $cataloguePrice;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
        ]);
        $this->unitClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
            'size' => '10.00',
        ]);
        [, $this->cataloguePrice] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->unitClass->update(['current_price_id' => $this->cataloguePrice->id]);

        Sanctum::actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_seed_fixture_and_footers(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'A-101',
            'enabled' => true,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
            'deposit_amount' => '200.00',
            'autopay_enabled' => true,
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
        $charge = Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-05-01',
        ]);
        $payment = Payment::factory()->cash('2026-05-10')->create([
            'contract_id' => $contract->id,
            'amount' => '40.00',
            'currency' => 'EUR',
        ]);
        \App\Models\Allocation::query()->create([
            'payment_id' => $payment->id,
            'charge_id' => $charge->id,
            'amount' => '40.00',
        ]);

        $report = new RentRollReport;
        $result = $report->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-06-15',
        ));

        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame('A-101', $row['unit_number']);
        $this->assertSame('S10', $row['class']);
        $this->assertSame('Madrid Hub', $row['site']);
        $this->assertSame('10.00', $row['area_m2']);
        $this->assertSame('Ada Lovelace', $row['tenant']);
        $this->assertSame($contract->id, $row['contract_id']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('2026-01-01', $row['move_in']);
        $this->assertSame('100.00', $row['monthly_rate']);
        $this->assertSame('200.00', $row['deposit_held']);
        $this->assertSame('60.00', $row['balance_owed']);
        $this->assertSame('60.00', $row['overdue']);
        $this->assertSame('yes', $row['autopay']);

        $balanceCol = collect($result->columns)->first(
            static fn ($c) => $c->key === 'balance_owed',
        );
        $this->assertNotNull($balanceCol);
        $this->assertStringContainsString('(current)', $balanceCol->label);
        $this->assertStringContainsString('current-state', $result->meta['notes'][0]);

        $this->assertSame(1, $result->meta['footer']['units']);
        $this->assertSame('10.00', $result->meta['footer']['area_m2']);
        $this->assertSame('100.00', $result->meta['footer']['monthly_rent']);
        $this->assertSame('200.00', $result->meta['footer']['deposits']);
        $this->assertSame('60.00', $result->meta['footer']['overdue']);
        $this->assertSame('EUR', $result->meta['footer']['currency']);

        $json = $this->getJson('/api/reports/rent-roll?as_of=2026-06-15&site_ids[]='.$this->site->id);
        $json->assertOk();
        $json->assertJsonPath('data.rows.0.unit_number', 'A-101');
        $json->assertJsonPath('data.meta.footer.units', 1);
    }

    public function test_as_of_edges(): void
    {
        $classB = UnitClass::factory()->create([
            'code' => 'M20',
            'label' => 'Medium 20',
            'size' => '20.00',
        ]);
        $this->createUnitClassCataloguePrice(
            $classB->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '150.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );

        $origin = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'T-01',
            'enabled' => true,
        ]);
        $destination = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $classB->id,
            'unit_number' => 'T-02',
            'enabled' => true,
        ]);
        $vacateUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'V-01',
            'enabled' => true,
        ]);
        $gapUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'G-01',
            'enabled' => true,
        ]);
        $awaitUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'W-01',
            'enabled' => true,
        ]);

        // Transfer: origin ended 2026-03-10, destination started same day.
        $transferContact = Contact::factory()->create(['first_name' => 'Tr', 'last_name' => 'Ansfer']);
        $transferContract = Contract::factory()->create([
            'contact_id' => $transferContact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
            'deposit_amount' => '50.00',
        ]);
        ContractItem::query()->create([
            'contract_id' => $transferContract->id,
            'item_type' => 'unit',
            'item_id' => $destination->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-03-10',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $origin->id,
            'contract_id' => $transferContract->id,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-03-10',
            'ended_reason' => 'transferred_out',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $destination->id,
            'contract_id' => $transferContract->id,
            'started_on' => '2026-03-10',
            'ended_on' => null,
        ]);

        // Notice tenancy with end date.
        $noticeContact = Contact::factory()->create(['first_name' => 'No', 'last_name' => 'Tice']);
        $noticeUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'N-01',
            'enabled' => true,
        ]);
        $noticeContract = Contract::factory()->create([
            'contact_id' => $noticeContact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::NoticeGiven,
            'move_in_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'notice_given_on' => '2026-03-01',
            'deposit_amount' => '75.00',
        ]);
        ContractItem::query()->create([
            'contract_id' => $noticeContract->id,
            'item_type' => 'unit',
            'item_id' => $noticeUnit->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $noticeUnit->id,
            'contract_id' => $noticeContract->id,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-04-30',
        ]);

        // Vacate exclusive end on 2026-03-15.
        $vacateContract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Ended,
            'move_in_date' => '2026-01-01',
            'deposit_amount' => '10.00',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $vacateUnit->id,
            'contract_id' => $vacateContract->id,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-03-15',
            'ended_reason' => 'vacated',
        ]);

        // Gap day: vacated 2026-03-20, re-let 2026-03-22 — gap on 2026-03-21.
        $oldGap = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Ended,
            'move_in_date' => '2026-01-01',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $gapUnit->id,
            'contract_id' => $oldGap->id,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-03-20',
            'ended_reason' => 'vacated',
        ]);
        $newGap = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-03-22',
            'deposit_amount' => '30.00',
        ]);
        ContractItem::query()->create([
            'contract_id' => $newGap->id,
            'item_type' => 'unit',
            'item_id' => $gapUnit->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-03-22',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $gapUnit->id,
            'contract_id' => $newGap->id,
            'started_on' => '2026-03-22',
            'ended_on' => null,
        ]);

        // awaiting_signature must never appear (even if a rogue occupancy existed).
        $awaitContract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::AwaitingSignature,
            'move_in_date' => '2026-03-01',
            'signed_at' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $awaitUnit->id,
            'contract_id' => $awaitContract->id,
            'started_on' => '2026-03-01',
            'ended_on' => null,
        ]);

        $report = new RentRollReport;

        // Mid-transfer: destination only.
        $midTransfer = $report->run(new ReportFilters(siteIds: [$this->site->id], asOf: '2026-03-10'));
        $unitsMid = collect($midTransfer->rows)->pluck('unit_number')->all();
        $this->assertContains('T-02', $unitsMid);
        $this->assertNotContains('T-01', $unitsMid);

        // Mid-notice: tenancy with end date.
        $midNotice = $report->run(new ReportFilters(siteIds: [$this->site->id], asOf: '2026-03-15'));
        $noticeRow = collect($midNotice->rows)->firstWhere('unit_number', 'N-01');
        $this->assertNotNull($noticeRow);
        $this->assertSame('notice_given', $noticeRow['status']);
        $this->assertSame('2026-04-30', $noticeRow['end_date']);

        // Vacate day (exclusive end): vacated unit absent.
        $this->assertNull(collect($midNotice->rows)->firstWhere('unit_number', 'V-01'));

        // Gap day: empty for G-01.
        $gapDay = $report->run(new ReportFilters(siteIds: [$this->site->id], asOf: '2026-03-21'));
        $this->assertNull(collect($gapDay->rows)->firstWhere('unit_number', 'G-01'));

        // awaiting_signature absent on all as-of dates.
        foreach (['2026-03-10', '2026-03-15', '2026-03-21', '2026-06-15'] as $day) {
            $rows = $report->run(new ReportFilters(siteIds: [$this->site->id], asOf: $day))->rows;
            $this->assertNull(
                collect($rows)->firstWhere('unit_number', 'W-01'),
                "awaiting_signature unit should be absent on {$day}",
            );
            $this->assertFalse(
                collect($rows)->contains(fn (array $r): bool => $r['status'] === 'awaiting_signature'),
            );
        }
    }
}
