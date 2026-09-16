<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->unusedCountryCode(),
            'name' => fake()->country(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Country $country): void {
            $code = strtoupper((string) $country->code);
            $existing = Country::query()->where('code', $code)->first();

            if ($existing === null) {
                $country->code = $code;

                return;
            }

            $country->setRawAttributes($existing->getAttributes(), true);
            $country->exists = true;
            $country->syncOriginal();
        });
    }

    private function unusedCountryCode(): string
    {
        for ($i = 0; $i < 80; $i++) {
            $code = strtoupper((string) fake()->unique()->countryCode());
            if (! Country::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper((string) fake()->unique()->bothify('??'));
    }
}
