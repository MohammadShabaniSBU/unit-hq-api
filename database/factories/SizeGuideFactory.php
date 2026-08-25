<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SizeGuideMetric;
use App\Models\SizeGuide;
use App\Support\Facility\SizeGuideResolver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SizeGuide>
 */
class SizeGuideFactory extends Factory
{
    protected $model = SizeGuide::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => null,
            'unit_class_id' => null,
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_size' => '12.00',
            'max_size' => '16.00',
            'min_quantity' => 17,
            'max_quantity' => 28,
            'notes' => SizeGuideResolver::DISCLAIMER,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
