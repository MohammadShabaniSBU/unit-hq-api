<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\CredentialStatus;
use App\Models\AccessProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessProviderAccount>
 */
class AccessProviderAccountFactory extends Factory
{
    protected $model = AccessProviderAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AccessProviderName::Sensorberg,
            'display_name' => 'Sensorberg',
            'credentials' => [
                'client_id' => 'test_client_'.Str::random(8),
                'client_secret' => 'test_secret_'.Str::random(16),
            ],
            'webhook_token' => Str::random(40),
            'webhook_state' => AccessWebhookState::Unconfigured,
            'webhook_endpoint_ids' => null,
            'status' => CredentialStatus::Connected,
            'last_error' => null,
            'is_active' => true,
            'discovered_points' => null,
            'points_discovered_at' => null,
        ];
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'status' => CredentialStatus::Disconnected,
            'is_active' => false,
        ]);
    }
}
