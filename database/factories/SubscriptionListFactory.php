<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SubscriptionList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionList>
 */
class SubscriptionListFactory extends Factory
{
    protected $model = SubscriptionList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'key' => $key,
            'labels' => [
                'en' => fake()->words(2, true),
                'es' => fake()->words(2, true),
                'fr' => fake()->words(2, true),
            ],
            'descriptions' => null,
            'kind' => 'newsletter',
            'channels' => ['email'],
            'site_id' => null,
            'is_public' => true,
            'requires_double_opt_in' => true,
            'is_system' => false,
            'archived_at' => null,
        ];
    }
}
