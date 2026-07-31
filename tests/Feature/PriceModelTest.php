<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class PriceModelTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private UnitClass $unitClass;

    private Site $site;

    private UnitClassRate $rate;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $this->unitClass = UnitClass::factory()->create();
        [$this->rate, $this->price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
    }

    public function test_one_current_catalogue_price_per_owner(): void
    {
        $this->expectException(QueryException::class);

        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id'   => $this->rate->id,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '120.00',
            'currency'       => 'EUR',
            'effective_from' => '2026-06-01',
            'effective_to'   => null,
            'created_by'     => $this->employee->id,
        ]);
    }

    public function test_contract_scope_rejects_windows(): void
    {
        $this->expectException(QueryException::class);

        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id'   => $this->rate->id,
            'scope'          => Price::SCOPE_CONTRACT,
            'amount'         => '90.00',
            'currency'       => 'EUR',
            'effective_from' => '2026-01-01',
            'effective_to'   => null,
            'created_by'     => $this->employee->id,
        ]);
    }

    public function test_catalogue_scope_requires_owner_and_window(): void
    {
        $this->expectException(QueryException::class);

        Price::query()->create([
            'priceable_type' => null,
            'priceable_id'   => null,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '90.00',
            'currency'       => 'EUR',
            'effective_from' => '2026-01-01',
            'effective_to'   => null,
            'created_by'     => $this->employee->id,
        ]);
    }

    public function test_amount_update_is_impossible(): void
    {
        $this->expectException(RuntimeException::class);

        $this->price->update(['amount' => '200.00']);
    }

    public function test_effective_to_settable_once_only(): void
    {
        $this->price->update(['effective_to' => '2026-06-01']);

        $this->expectException(RuntimeException::class);
        $this->price->refresh()->update(['effective_to' => '2026-07-01']);
    }

    public function test_catalogue_change_leaves_junction_untouched(): void
    {
        $junctionId = $this->rate->id;
        $junctionCountBefore = UnitClassRate::query()->count();

        $this->price->update(['effective_to' => '2026-06-01']);
        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id'   => $this->rate->id,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '120.00',
            'currency'       => 'EUR',
            'effective_from' => '2026-06-01',
            'effective_to'   => null,
            'created_by'     => $this->employee->id,
        ]);

        $this->assertSame($junctionCountBefore, UnitClassRate::query()->count());
        $this->assertSame($junctionId, $this->rate->fresh()->id);
        $this->assertSame('120.00', (string) $this->rate->fresh()->price->amount);
    }

    public function test_contract_reference_survives_catalogue_change(): void
    {
        $signedPriceId = $this->price->id;

        $this->price->update(['effective_to' => '2026-06-01']);
        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id'   => $this->rate->id,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '150.00',
            'currency'       => 'EUR',
            'effective_from' => '2026-06-01',
            'effective_to'   => null,
            'created_by'     => $this->employee->id,
        ]);

        $signed = Price::query()->findOrFail($signedPriceId);
        $this->assertSame('100.00', (string) $signed->amount);
        $this->assertSame('2026-06-01', $signed->effective_to?->toDateString());
    }
}
