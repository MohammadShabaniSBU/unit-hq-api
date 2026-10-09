<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsentText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentText>
 */
class ConsentTextFactory extends Factory
{
    protected $model = ConsentText::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'version' => 1,
            'locale' => 'en',
            'body' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
