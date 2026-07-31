<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\UnitOccupancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Postgres-only: asserts the gist exclusion constraint fires.
 * Skipped on SQLite (local/CI default).
 */
class OccupancyConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exclusion constraint is Postgres-only.');
        }
    }

    public function test_exclusion_constraint_blocks_overlap(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create();
        $price = Price::query()->create([
            'amount' => '100.00',
            'currency' => 'EUR',
            'billing_period' => 'monthly',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_by' => $employee->id,
        ]);
        UnitClassRate::query()->create([
            'unit_class_id' => $unitClass->id,
            'site_id' => $site->id,
            'price_id' => $price->id,
        ]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $contractA = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'move_in_date' => '2026-03-01',
            'start_date' => '2026-03-01',
        ]);
        $contractB = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'move_in_date' => '2026-03-15',
            'start_date' => '2026-03-15',
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contractA->id,
            'started_on' => '2026-03-01',
            'ended_on' => null,
        ]);

        $this->expectException(QueryException::class);

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contractB->id,
            'started_on' => '2026-03-15',
            'ended_on' => null,
        ]);
    }
}
