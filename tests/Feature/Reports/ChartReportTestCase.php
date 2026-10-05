<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\Allocation;
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
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

abstract class ChartReportTestCase extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    protected Employee $employee;

    protected Country $country;

    protected LegalEntity $entity;

    protected UnitClass $unitClass;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));

        $this->employee = Employee::factory()->manager()->create();
        $this->country = Country::factory()->create(['code' => 'ES']);
        $this->entity = LegalEntity::factory()->create();
        $this->unitClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
            'size' => '10.00',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function site(string $name, string $currency): Site
    {
        return Site::factory()->create([
            'country_id' => $this->country->id,
            'legal_entity_id' => $this->entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => $currency,
            'name' => $name,
        ]);
    }

    protected function unit(Site $site, string $number): Unit
    {
        return Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $number,
            'enabled' => true,
        ]);
    }

    protected function catalogue(Site $site, string $amount, string $currency): void
    {
        $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $site->id,
            $this->employee->id,
            ['amount' => $amount, 'currency' => $currency, 'effective_from' => '2025-01-01'],
        );
    }

    /**
     * @return array{contract: Contract, item: ContractItem}
     */
    protected function occupy(
        Unit $unit,
        string $amount,
        string $currency,
        string $from = '2025-01-01',
        ?string $endedOn = null,
        ?string $endedReason = null,
        ?Contract $contract = null,
    ): array {
        $price = Price::query()->create([
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => $amount,
            'currency' => $currency,
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $contract ??= Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => $currency,
            'status' => ContractStatus::Active,
            'move_in_date' => $from,
            'deposit_amount' => '0.00',
        ]);

        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $from,
            'effective_to' => null,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'started_on' => $from,
            'ended_on' => $endedOn,
            'ended_reason' => $endedReason,
        ]);

        return ['contract' => $contract, 'item' => $item];
    }

    protected function charge(
        Contract $contract,
        ChargeType $type,
        string $amount,
        string $on,
        ?int $reversalOf = null,
        ?string $currency = null,
    ): Charge {
        return Charge::query()->create([
            'contract_id' => $contract->id,
            'charge_type' => $type,
            'net_amount' => $amount,
            'amount' => $amount,
            'currency' => $currency ?? $contract->currency,
            'tax_amount' => 0,
            'period_start' => $type === ChargeType::Deposit ? null : $on,
            'due_date' => $on,
            'reversal_of_charge_id' => $reversalOf,
        ]);
    }

    protected function allocate(Charge $charge, string $amount, string $at): void
    {
        $payment = Payment::factory()->create([
            'contract_id' => $charge->contract_id,
            'amount' => $amount,
            'currency' => $charge->currency,
        ]);

        $allocation = new Allocation([
            'payment_id' => $payment->id,
            'charge_id' => $charge->id,
            'amount' => $amount,
        ]);
        $allocation->created_at = CarbonImmutable::parse($at, 'UTC');
        $allocation->save();
    }

    /**
     * @return list<string>
     */
    protected function chartKeys(ReportResult $result): array
    {
        return array_map(
            static fn (ChartSpec $chart): string => $chart->key,
            $result->charts,
        );
    }
}
