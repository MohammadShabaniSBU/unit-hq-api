<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Contact;
use App\Models\Reservation;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id'         => Unit::factory(),
            'contact_id'      => Contact::factory(),
            'deal_id'         => null,
            'offer_option_id' => null,
            'status'          => ReservationStatus::Pending,
            'expires_at'      => now()->addDays(14),
            'hold_notes'      => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReservationStatus::Confirmed,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'     => ReservationStatus::Expired,
            'expires_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }
}
