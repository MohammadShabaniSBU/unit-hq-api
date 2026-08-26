<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountKind;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => '20% off',
            'kind' => DiscountKind::Percent,
            'params' => ['percent' => '20.00'],
            'applies_to' => 'unit',
            'tracks_rate_changes' => true,
            'agent_offerable' => false,
            'customer_terms' => null,
            'archived_at' => null,
            'created_by' => null,
        ];
    }

    public function percent(string $percent = '10.00'): static
    {
        return $this->state(fn () => [
            'name' => rtrim(rtrim($percent, '0'), '.').'% off',
            'kind' => DiscountKind::Percent,
            'params' => ['percent' => $percent],
            'tracks_rate_changes' => true,
        ]);
    }

    public function freeTime(): static
    {
        return $this->state(fn () => [
            'name' => 'Long-stay promo',
            'kind' => DiscountKind::FreeTime,
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 4, 'free_weeks' => 2],
                    ['min_commitment_weeks' => 8, 'free_weeks' => 4],
                    ['min_commitment_weeks' => 12, 'free_weeks' => 6],
                ],
            ],
            'tracks_rate_changes' => false,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'archived_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $terms
     */
    public function agentOfferable(array $terms = ['en' => 'Commit to 4 weeks or more and your first 2 weeks are free.']): static
    {
        return $this->state(fn () => [
            'agent_offerable' => true,
            'customer_terms' => $terms,
        ]);
    }
}
