<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    protected $model = Segment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => null,
            'entity_type' => 'contact',
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'prospect'],
                ],
            ],
            'is_shared' => true,
            'created_by' => null,
            'archived_at' => null,
        ];
    }
}
