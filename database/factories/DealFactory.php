<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Enums\StayPeriod;
use App\Enums\StorageReason;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Site;
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
            'site_id'               => Site::query()->inRandomOrder()->value('id') ?? Site::factory(),
            'status'                => fake()->randomElement(DealStatus::cases()),
            'expected_move_in'      => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'expected_stay_length'  => $stayLength,
            'expected_stay_period'  => $stayPeriod,
            'storage_reason'        => fake()->boolean(80)
                ? fake()->randomElement(StorageReason::cases())->value
                : null,
            'desired_size'          => $unitClass?->size ?? fake()->randomFloat(2, 5, 30),
            'desired_unit_class_id' => $unitClass?->id,
            'intent_notes'          => fake()->optional(0.6)->sentence(),
        ];
    }
}
