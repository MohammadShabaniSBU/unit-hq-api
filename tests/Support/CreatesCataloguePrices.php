<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\InsuranceRate;
use App\Models\Price;
use App\Models\UnitClassRate;

trait CreatesCataloguePrices
{
    /**
     * @param  array<string, mixed>  $priceAttrs
     * @return array{0: UnitClassRate, 1: Price}
     */
    protected function createUnitClassCataloguePrice(
        int $unitClassId,
        int $siteId,
        int $createdBy,
        array $priceAttrs = [],
    ): array {
        $rate = UnitClassRate::query()->firstOrCreate([
            'unit_class_id' => $unitClassId,
            'site_id'       => $siteId,
        ]);

        $price = Price::query()->create(array_merge([
            'priceable_type' => 'unit_class_rate',
            'priceable_id'   => $rate->id,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '100.00',
            'currency'       => 'EUR',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to'   => null,
            'created_by'     => $createdBy,
        ], $priceAttrs));

        return [$rate, $price];
    }

    /**
     * @param  array<string, mixed>  $priceAttrs
     * @return array{0: InsuranceRate, 1: Price}
     */
    protected function createInsuranceCataloguePrice(
        int $insuranceId,
        int $siteId,
        int $createdBy,
        array $priceAttrs = [],
    ): array {
        $rate = InsuranceRate::query()->firstOrCreate([
            'insurance_id' => $insuranceId,
            'site_id'      => $siteId,
        ]);

        $price = Price::query()->create(array_merge([
            'priceable_type' => 'insurance_rate',
            'priceable_id'   => $rate->id,
            'scope'          => Price::SCOPE_CATALOGUE,
            'amount'         => '5.00',
            'currency'       => 'EUR',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to'   => null,
            'created_by'     => $createdBy,
        ], $priceAttrs));

        return [$rate, $price];
    }
}
