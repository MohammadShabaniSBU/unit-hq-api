<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAgent>
 */
class AiAgentFactory extends Factory
{
    protected $model = AiAgent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => 'Support Agent',
            'description' => null,
            'model' => config('agents.default_model', 'claude-sonnet-4-6'),
            'is_active' => true,
            'settings' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'archived_at' => now(),
        ]);
    }
}
