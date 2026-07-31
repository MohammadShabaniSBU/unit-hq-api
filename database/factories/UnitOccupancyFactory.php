<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitOccupancy>
 */
class UnitOccupancyFactory extends Factory
{
    protected $model = UnitOccupancy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-3 months', '-1 week');

        return [
            'unit_id'     => Unit::factory(),
            'contract_id' => Contract::factory(),
            'started_on'  => $started->format('Y-m-d'),
            'ended_on'    => null,
            'ended_reason'=> null,
            'created_by'  => null,
        ];
    }

    public function ended(string $endedOn, string $reason = 'vacated'): static
    {
        return $this->state(fn (): array => [
            'ended_on'     => $endedOn,
            'ended_reason' => $reason,
        ]);
    }
}
