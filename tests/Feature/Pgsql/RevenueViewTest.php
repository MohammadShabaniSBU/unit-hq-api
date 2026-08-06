<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RevenueViewTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Analytics views are Postgres-only.');
        }

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();
        $this->contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);
        ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    public function test_excludes_deposit_and_write_off(): void
    {
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '50.00',
            'amount' => '50.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Deposit,
            'net_amount' => '200.00',
            'amount' => '200.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::WriteOff,
            'net_amount' => '10.00',
            'amount' => '10.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Refund,
            'net_amount' => '5.00',
            'amount' => '5.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);

        $ids = collect(DB::select('select charge_id, charge_type from analytics.v_revenue'))
            ->pluck('charge_type')
            ->all();

        $this->assertSame(['rent'], $ids);
    }

    public function test_excludes_reversed_and_reversal_rows(): void
    {
        $original = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '80.00',
            'amount' => '80.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '-80.00',
            'amount' => '-80.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
            'reversal_of_charge_id' => $original->id,
        ]);
        $kept = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '25.00',
            'amount' => '25.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-02',
        ]);

        $ids = collect(DB::select('select charge_id from analytics.v_revenue'))
            ->pluck('charge_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$kept->id], $ids);
    }

    public function test_matches_native_revenue_report(): void
    {
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'net_amount' => '100.00',
            'amount' => '121.00',
            'tax_amount' => '21.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
            'period_start' => '2026-06-01',
            'period_end' => '2026-07-01',
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::LateFee,
            'net_amount' => '15.50',
            'amount' => '15.50',
            'currency' => 'EUR',
            'due_date' => '2026-06-15',
        ]);
        $reversed = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Insurance,
            'net_amount' => '9.00',
            'amount' => '9.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Insurance,
            'net_amount' => '-9.00',
            'amount' => '-9.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
            'reversal_of_charge_id' => $reversed->id,
        ]);
        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Deposit,
            'net_amount' => '200.00',
            'amount' => '200.00',
            'currency' => 'EUR',
            'due_date' => '2026-06-01',
        ]);

        $expected = Charge::query()
            ->whereNull('reversal_of_charge_id')
            ->whereNotIn('charge_type', [
                ChargeType::Deposit->value,
                ChargeType::WriteOff->value,
                ChargeType::Refund->value,
            ])
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('charges as r')
                    ->whereColumn('r.reversal_of_charge_id', 'charges.id');
            })
            ->selectRaw('currency, coalesce(sum(net_amount), 0) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
            ->all();

        $actual = collect(DB::select(
            'select currency, coalesce(sum(net_amount), 0) as total from analytics.v_revenue group by currency',
        ))->mapWithKeys(fn ($row) => [
            $row->currency => number_format((float) $row->total, 2, '.', ''),
        ])->all();

        $this->assertSame($expected, $actual);
        $this->assertSame('115.50', $actual['EUR']);
    }
}
