<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'unit_class_id' => UnitClass::factory(),
            'unit_number' => fake()->unique()->numerify('A-###'),
            'actual_width' => null,
            'actual_depth' => null,
            'actual_height' => null,
            'note' => null,
            'enabled' => true,
        ];
    }
}
