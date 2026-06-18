<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Enums\StayPeriod;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\UnitClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stayLength = fake()->numberBetween(1, 12);
        $stayPeriod = fake()->randomElement(StayPeriod::cases());
        $unitClass = UnitClass::query()->inRandomOrder()->first();

        return [
            'contact_id'            => Contact::factory(),
            'status'                => fake()->randomElement(DealStatus::cases()),
            'expected_value'        => fake()->randomFloat(2, 50, 500),
            'expected_move_in'      => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'expected_stay_length'  => $stayLength,
            'expected_stay_period'  => $stayPeriod,
            'storage_reason'        => fake()->randomElement([
                'Moving house',
                'Business inventory overflow',
                'Renovation storage',
                'Seasonal stock',
                'Document archive',
                'Furniture between homes',
            ]),
            'desired_size'          => $unitClass?->size ?? fake()->randomFloat(2, 5, 30),
            'desired_unit_class_id' => $unitClass?->id,
            'intent_notes'          => fake()->optional(0.6)->sentence(),
        ];
    }
}
