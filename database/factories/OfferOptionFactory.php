<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
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
        $rate = UnitClassRate::query()
            ->with(['unitClass', 'site'])
            ->inRandomOrder()
            ->first();

        $unit = null;
        if ($rate !== null) {
            $on = $rate->site !== null
                ? SiteClock::today($rate->site)
                : CarbonImmutable::parse('1970-01-01');

            $unit = Unit::query()
                ->availableOn($on)
                ->where('unit_class_id', $rate->unit_class_id)
                ->where('site_id', $rate->site_id)
                ->inRandomOrder()
                ->first();
        }

        return [
            'offer_id'           => Offer::factory(),
            'unit_class_rate_id' => $rate?->id,
            'unit_id'            => $unit?->id,
            'label'              => $rate?->unitClass->label ?? fake()->sentence(3),
            'description'        => fake()->optional(0.5)->sentence(),
            'display_order'      => 0,
            'selected_at'        => null,
        ];
    }
}
