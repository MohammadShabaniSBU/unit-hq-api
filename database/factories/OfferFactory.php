<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id'         => Deal::factory(),
            'contact_id'      => Contact::factory(),
            'token'           => Str::random(32),
            'status'          => 'draft',
            'expires_at'      => now()->addDays(30),
            'sent_at'         => null,
            'first_viewed_at' => null,
            'accepted_at'     => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'  => 'sent',
            'sent_at' => now()->subDays(fake()->numberBetween(1, 14)),
        ]);
    }

    public function viewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'          => 'viewed',
            'sent_at'         => now()->subDays(fake()->numberBetween(3, 14)),
            'first_viewed_at' => now()->subDays(fake()->numberBetween(1, 2)),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'          => 'accepted',
            'sent_at'         => now()->subDays(fake()->numberBetween(5, 20)),
            'first_viewed_at' => now()->subDays(fake()->numberBetween(3, 4)),
            'accepted_at'     => now()->subDays(fake()->numberBetween(1, 2)),
        ]);
    }
}
