<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessGrantState;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\Contact;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    protected $model = AccessGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'access_point_id' => AccessPoint::factory(),
            'contact_id' => Contact::factory(),
            'contract_id' => Contract::factory(),
            'provider_grant_id' => null,
            'state' => AccessGrantState::Applied,
            'last_error' => null,
            'pin' => null,
            'pin_shown_at' => null,
            'applied_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function applying(): static
    {
        return $this->state(fn (): array => [
            'state' => AccessGrantState::Applying,
            'applied_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'state' => AccessGrantState::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
