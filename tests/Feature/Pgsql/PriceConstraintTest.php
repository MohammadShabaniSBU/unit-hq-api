<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\Country;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Site;
use App\Models\UnitClass;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Postgres-only: catalogue price window exclusion constraint.
 */
class PriceConstraintTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exclusion constraint is Postgres-only.');
        }
    }

    public function test_catalogue_windows_never_overlap(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $unitClass = UnitClass::factory()->create();

        [$rate] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            [
                'amount'         => '100.00',
                'effective_from' => '2026-01-01',
                'effective_to'   => '2026-06-01',
            ],
        );

        $this->expectException(QueryException::class);

        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id'   => $rate->id,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '120.00',
            'currency'       => 'EUR',
            'effective_from' => '2026-05-01',
            'effective_to'   => '2026-08-01',
            'created_by'     => $employee->id,
        ]);
    }
}
