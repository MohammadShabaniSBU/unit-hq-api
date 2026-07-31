<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\HoldType;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Postgres-only: asserts the gist exclusion constraint and overlock exemption.
 * Skipped on SQLite (local/CI default).
 */
class HoldConstraintTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exclusion constraint is Postgres-only.');
        }

        Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
        $unitClass = UnitClass::factory()->create();
        $this->unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    public function test_overlock_may_overlap_other_holds(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-03-01',
            'ends_on' => null,
            'reason' => 'Flood',
        ]);

        // Overlock is exempt from the exclusion constraint.
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => '2026-03-01',
            'ends_on' => null,
        ]);

        $this->assertDatabaseCount('unit_holds', 2);
    }

    public function test_exclusion_constraint_blocks_overlapping_blocking_holds(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-03-01',
            'ends_on' => null,
            'reason' => 'Flood',
        ]);

        $this->expectException(QueryException::class);

        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Damaged,
            'starts_on' => '2026-03-15',
            'ends_on' => null,
            'reason' => 'Door broken',
        ]);
    }
}
