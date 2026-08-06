<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Time\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class UnitStateDailyTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Unit $unit;

    private Contract $contract;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Analytics MV is Postgres-only.');
        }

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'UTC',
            'currency' => 'EUR',
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'enabled' => true,
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
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    public function test_exclusive_end_boundary(): void
    {
        $today = SiteClock::today($this->site)->format('Y-m-d');
        $moveOut = SiteClock::today($this->site)->subDays(5)->format('Y-m-d');
        $dayBefore = SiteClock::today($this->site)->subDays(6)->format('Y-m-d');

        $contactB = Contact::factory()->create();
        $contractB = Contract::factory()->create([
            'contact_id' => $contactB->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);

        ContractItem::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => $moveOut]);

        ContractItem::query()->create([
            'contract_id' => $contractB->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => $moveOut,
            'effective_to' => null,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $this->contract->id,
            'started_on' => SiteClock::today($this->site)->subDays(20)->format('Y-m-d'),
            'ended_on' => $moveOut,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $contractB->id,
            'started_on' => $moveOut,
            'ended_on' => null,
        ]);

        $this->refreshUnitStateDaily();

        $states = collect(DB::select(
            'select day::text as day, state, contract_id from analytics.mv_unit_state_daily
             where unit_id = ? and day in (?, ?) order by day',
            [$this->unit->id, $dayBefore, $moveOut],
        ));

        $this->assertCount(2, $states);
        $this->assertSame('occupied', $states[0]->state);
        $this->assertSame($this->contract->id, (int) $states[0]->contract_id);
        $this->assertSame('occupied', $states[1]->state);
        $this->assertSame($contractB->id, (int) $states[1]->contract_id);
        $this->assertNotSame($dayBefore, $today);
    }

    public function test_overlock_does_not_mask_occupied(): void
    {
        $today = SiteClock::today($this->site)->format('Y-m-d');

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $this->contract->id,
            'started_on' => SiteClock::today($this->site)->subDays(10)->format('Y-m-d'),
            'ended_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => SiteClock::today($this->site)->subDays(10)->format('Y-m-d'),
            'ends_on' => null,
        ]);

        $this->refreshUnitStateDaily();

        $row = DB::selectOne(
            'select state from analytics.mv_unit_state_daily where unit_id = ? and day = ?',
            [$this->unit->id, $today],
        );

        $this->assertNotNull($row);
        $this->assertSame('occupied', $row->state);
    }

    public function test_matches_unit_class_matrix_today(): void
    {
        Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'enabled' => true,
            'unit_number' => 'FREE-1',
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $this->contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        $this->refreshUnitStateDaily();

        $on = SiteClock::today($this->site)->format('Y-m-d');

        $matrixOccupied = (int) UnitOccupancy::query()
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->where('units.site_id', $this->site->id)
            ->where('units.unit_class_id', $this->unitClass->id)
            ->where('units.enabled', true)
            ->where('unit_occupancies.started_on', '<=', $on)
            ->where(function ($q) use ($on): void {
                $q->whereNull('unit_occupancies.ended_on')
                    ->orWhere('unit_occupancies.ended_on', '>', $on);
            })
            ->selectRaw('COUNT(DISTINCT unit_occupancies.unit_id) as occupied')
            ->value('occupied');

        $viewOccupied = (int) DB::selectOne(
            "select count(*)::int as n from analytics.mv_unit_state_daily m
             join units u on u.id = m.unit_id
             where m.day = ? and m.state = 'occupied'
               and u.site_id = ? and u.unit_class_id = ? and u.enabled = true",
            [$on, $this->site->id, $this->unitClass->id],
        )->n;

        $this->assertSame(1, $matrixOccupied);
        $this->assertSame($matrixOccupied, $viewOccupied);
    }

    private function refreshUnitStateDaily(): void
    {
        // Non-concurrent refresh — same SQL path the command uses inside tests.
        // Avoid Artisan::call here: SystemEvent::record() can abort the
        // RefreshDatabase transaction when partitioned inserts fail.
        DB::statement('REFRESH MATERIALIZED VIEW analytics.mv_unit_state_daily');
    }
}
