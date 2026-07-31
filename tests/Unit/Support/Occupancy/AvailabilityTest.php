<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Occupancy;

use App\Enums\HoldType;
use App\Enums\UnitState;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\Availability;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Country $country;

    private UnitClass $unitClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->country = Country::factory()->create(['code' => 'ES']);
        $this->unitClass = UnitClass::factory()->create();
    }

    public function test_occupied_unit_unavailable(): void
    {
        $site = $this->site('Europe/Madrid');
        $unit = $this->unit($site);
        $on = CarbonImmutable::parse('2026-07-01');

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $this->contract($unit)->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
        ]);

        $this->assertFalse(Availability::isAvailable($unit->id, $on));
        $this->assertSame(UnitState::Occupied, Availability::stateOn($unit->id, $on));
    }

    public function test_held_unit_unavailable(): void
    {
        $site = $this->site('Europe/Madrid');
        $unit = $this->unit($site);
        $on = CarbonImmutable::parse('2026-07-01');

        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
            'reason' => 'Repair',
        ]);

        $this->assertFalse(Availability::isAvailable($unit->id, $on));
        $this->assertSame(UnitState::Maintenance, Availability::stateOn($unit->id, $on));
    }

    public function test_overlocked_unit_not_double_counted(): void
    {
        $site = $this->site('Europe/Madrid');
        $unit = $this->unit($site);
        $on = CarbonImmutable::parse('2026-07-01');

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $this->contract($unit)->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => '2026-06-15',
            'ends_on' => null,
        ]);

        $this->assertFalse(Availability::isAvailable($unit->id, $on));
        $this->assertSame(UnitState::Occupied, Availability::stateOn($unit->id, $on));

        // Overlock alone does not block.
        $free = $this->unit($site);
        UnitHold::query()->create([
            'unit_id' => $free->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => '2026-06-15',
            'ends_on' => null,
        ]);
        $this->assertTrue(Availability::isAvailable($free->id, $on));
        $this->assertSame(UnitState::Available, Availability::stateOn($free->id, $on));
    }

    public function test_released_hold_does_not_block(): void
    {
        $site = $this->site('Europe/Madrid');
        $unit = $this->unit($site);
        $on = CarbonImmutable::parse('2026-07-01');

        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
            'released_at' => '2026-06-20 12:00:00',
            'reason' => 'Done',
        ]);

        $this->assertTrue(Availability::isAvailable($unit->id, $on));
    }

    public function test_expired_hold_does_not_block(): void
    {
        $site = $this->site('Europe/Madrid');
        $unit = $this->unit($site);
        $on = CarbonImmutable::parse('2026-07-01');

        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Reservation,
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-20',
            'released_at' => null,
        ]);

        $this->assertTrue(Availability::isAvailable($unit->id, $on));
    }

    public function test_ended_occupancy_frees_unit_same_day(): void
    {
        $site = $this->site('Europe/Madrid');
        $unit = $this->unit($site);
        $on = CarbonImmutable::parse('2026-07-01');

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $this->contract($unit)->id,
            'started_on' => '2026-06-01',
            'ended_on' => '2026-07-01',
        ]);

        $this->assertTrue(Availability::isAvailable($unit->id, $on));
        $this->assertSame(UnitState::Available, Availability::stateOn($unit->id, $on));
    }

    public function test_available_scope_has_no_n_plus_one(): void
    {
        $site = $this->site('Europe/Madrid');
        $on = CarbonImmutable::parse('2026-07-01');

        foreach (range(1, 25) as $i) {
            $unit = $this->unit($site);
            if ($i % 3 === 0) {
                UnitOccupancy::query()->create([
                    'unit_id' => $unit->id,
                    'contract_id' => $this->contract($unit)->id,
                    'started_on' => '2026-06-01',
                    'ended_on' => null,
                ]);
            } elseif ($i % 3 === 1) {
                UnitHold::query()->create([
                    'unit_id' => $unit->id,
                    'hold_type' => HoldType::Maintenance,
                    'starts_on' => '2026-06-01',
                    'ends_on' => null,
                    'reason' => 'x',
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = Unit::query()->availableOn($on)->pluck('id');
        $this->assertGreaterThan(0, $ids->count());

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One select with two whereNotExists — not one query per unit.
        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_state_scope_filters_occupied_and_out_of_service(): void
    {
        $site = $this->site('Europe/Madrid');
        $on = CarbonImmutable::parse('2026-07-01');

        $available = $this->unit($site);
        $occupied = $this->unit($site);
        $reserved = $this->unit($site);
        $maintenance = $this->unit($site);
        $damaged = $this->unit($site);

        UnitOccupancy::query()->create([
            'unit_id' => $occupied->id,
            'contract_id' => $this->contract($occupied)->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $reserved->id,
            'hold_type' => HoldType::Reservation,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $maintenance->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
            'reason' => 'Paint',
        ]);
        UnitHold::query()->create([
            'unit_id' => $damaged->id,
            'hold_type' => HoldType::Damaged,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
            'reason' => 'Door',
        ]);

        $occupiedIds = Unit::query()
            ->tap(fn ($q) => Availability::scopeStateOn($q, UnitState::Occupied, $on))
            ->pluck('id');
        $this->assertTrue($occupiedIds->contains($occupied->id));
        $this->assertFalse($occupiedIds->contains($available->id));

        $reservedIds = Unit::query()
            ->tap(fn ($q) => Availability::scopeStateOn($q, UnitState::Reserved, $on))
            ->pluck('id');
        $this->assertTrue($reservedIds->contains($reserved->id));
        $this->assertFalse($reservedIds->contains($maintenance->id));

        $oosIds = Unit::query()
            ->tap(fn ($q) => Availability::scopeStateGroupOn($q, 'out_of_service', $on))
            ->pluck('id');
        $this->assertTrue($oosIds->contains($maintenance->id));
        $this->assertTrue($oosIds->contains($damaged->id));
        $this->assertFalse($oosIds->contains($reserved->id));
        $this->assertFalse($oosIds->contains($occupied->id));
        $this->assertFalse($oosIds->contains($available->id));
    }

    public function test_cross_site_today_is_per_site_timezone(): void
    {
        // London still Mar 14; Madrid already Mar 15.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 23:30:00', 'UTC'));

        $london = $this->site('Europe/London', 'GBP', 'GB');
        $madrid = $this->site('Europe/Madrid', 'EUR', 'ES');

        $londonUnit = $this->unit($london);
        $madridUnit = $this->unit($madrid);

        // Occupancy starts Mar 15 — blocks Madrid today, not London today.
        UnitOccupancy::query()->create([
            'unit_id' => $londonUnit->id,
            'contract_id' => $this->contract($londonUnit)->id,
            'started_on' => '2026-03-15',
            'ended_on' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $madridUnit->id,
            'contract_id' => $this->contract($madridUnit)->id,
            'started_on' => '2026-03-15',
            'ended_on' => null,
        ]);

        $this->assertSame('2026-03-14', SiteClock::today($london)->format('Y-m-d'));
        $this->assertSame('2026-03-15', SiteClock::today($madrid)->format('Y-m-d'));

        $this->assertTrue(Availability::isAvailable($londonUnit->id, SiteClock::today($london)));
        $this->assertFalse(Availability::isAvailable($madridUnit->id, SiteClock::today($madrid)));

        $availableIds = Unit::query()
            ->tap(fn ($q) => Availability::scopeAvailableTodayPerSite($q))
            ->pluck('id');

        $this->assertTrue($availableIds->contains($londonUnit->id));
        $this->assertFalse($availableIds->contains($madridUnit->id));

        CarbonImmutable::setTestNow();
    }

    private function site(string $timezone, string $currency = 'EUR', string $countryCode = 'ES'): Site
    {
        $country = $countryCode === 'ES'
            ? $this->country
            : Country::factory()->create(['code' => $countryCode]);

        return Site::factory()->create([
            'country_id' => $country->id,
            'timezone' => $timezone,
            'currency' => $currency,
        ]);
    }

    private function unit(Site $site): Unit
    {
        return Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
    }

    private function contract(Unit $unit): Contract
    {
        return Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => $unit->site->currency ?? 'EUR',
        ]);
    }
}
