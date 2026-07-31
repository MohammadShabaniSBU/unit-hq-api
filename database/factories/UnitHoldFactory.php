<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HoldType;
use App\Models\Unit;
use App\Models\UnitHold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitHold>
 */
class UnitHoldFactory extends Factory
{
    protected $model = UnitHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'unit_id'        => Unit::factory(),
            'hold_type'      => HoldType::Maintenance,
            'reservation_id' => null,
            'starts_on'      => $started->format('Y-m-d'),
            'ends_on'        => null,
            'released_at'    => null,
            'reason'         => 'Scheduled maintenance',
            'created_by'     => null,
        ];
    }

    public function reservation(): static
    {
        return $this->state(fn (): array => [
            'hold_type' => HoldType::Reservation,
            'reason'    => null,
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn (): array => [
            'hold_type' => HoldType::Maintenance,
            'reason'    => 'Scheduled maintenance',
        ]);
    }

    public function overlock(): static
    {
        return $this->state(fn (): array => [
            'hold_type' => HoldType::Overlock,
            'reason'    => null,
        ]);
    }

    public function released(?string $at = null): static
    {
        return $this->state(fn (): array => [
            'released_at' => $at ?? now()->toDateTimeString(),
        ]);
    }
}
