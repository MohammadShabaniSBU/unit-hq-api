<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessEventType;
use App\Models\AccessEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessEvent>
 */
class AccessEventFactory extends Factory
{
    protected $model = AccessEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'access_point_id' => null,
            'contact_id' => null,
            'access_grant_id' => null,
            'event_type' => AccessEventType::Granted,
            'occurred_at' => now(),
            'provider_credential_ref' => null,
            'provider_point_id' => fake()->bothify('point-????'),
            'raw' => ['source' => 'factory'],
            'created_at' => now(),
        ];
    }

    public function denied(): static
    {
        return $this->state(fn (): array => [
            'event_type' => AccessEventType::Denied,
        ]);
    }
}
