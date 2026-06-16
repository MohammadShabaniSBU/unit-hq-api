<?php

namespace Database\Factories;

use App\Models\UnitClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitClass>
 */
class UnitClassFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->regexify('[A-Z]{2,3}');

        return [
            'code' => $code,
            'label' => $code . ' Unit',
            'size' => fake()->randomFloat(2, 5, 50),
            'current_price_id' => null,
        ];
    }
}
