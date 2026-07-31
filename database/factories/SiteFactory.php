<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\LegalEntity;
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
            'code' => fake()->unique()->bothify('SITE-###'),
            'address' => fake()->streetAddress(),
            'address_line_2' => null,
            'location' => [
                'lat' => fake()->latitude(),
                'lng' => fake()->longitude(),
            ],
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'state_region' => fake()->state(),
            'country_id' => Country::query()->inRandomOrder()->value('id')
                ?? Country::factory(),
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'legal_entity_id' => LegalEntity::factory(),
            'archived_at' => null,
        ];
    }
}
