<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DelinquencyPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DelinquencyPolicy>
 */
class DelinquencyPolicyFactory extends Factory
{
    protected $model = DelinquencyPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'auto_release_overlock' => true,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'archived_at' => now(),
        ]);
    }
}
