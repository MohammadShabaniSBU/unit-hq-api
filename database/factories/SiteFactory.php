<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city() . ' Storage',
            'address' => fake()->streetAddress(),
            'location' => [
                'lat' => fake()->latitude(),
                'lng' => fake()->longitude(),
            ],
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'country_id' => Country::query()->inRandomOrder()->value('id')
                ?? Country::factory(),
        ];
    }
}
