<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\UnitClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferOption>
 */
class OfferOptionFactory extends Factory
{
    protected $model = OfferOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitClass = UnitClass::query()
            ->whereNotNull('current_price_id')
            ->inRandomOrder()
            ->first();

        return [
            'offer_id'      => Offer::factory(),
            'unit_class_id' => $unitClass?->id ?? UnitClass::factory(),
            'price_id'      => $unitClass?->current_price_id,
            'label'         => $unitClass ? $unitClass->label : fake()->sentence(3),
            'description'   => fake()->optional(0.5)->sentence(),
            'display_order' => 0,
            'selected_at'   => null,
        ];
    }
}
