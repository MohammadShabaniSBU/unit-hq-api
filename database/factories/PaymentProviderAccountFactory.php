<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CredentialStatus;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentProviderAccount>
 */
class PaymentProviderAccountFactory extends Factory
{
    protected $model = PaymentProviderAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory(),
            'provider' => 'stripe',
            'display_name' => 'Stripe',
            'publishable_key' => 'pk_test_'.Str::random(24),
            'secret_key' => 'sk_test_'.Str::random(24),
            'webhook_secret' => null,
            'webhook_endpoint_id' => null,
            'provider_account_id' => null,
            'account_token' => Str::random(40),
            'status' => CredentialStatus::Disconnected,
            'last_error' => null,
            'is_active' => true,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (): array => [
            'status' => CredentialStatus::Connected,
            'provider_account_id' => 'acct_test_'.Str::random(16),
            'secret_key' => 'sk_test_'.Str::random(24),
            'publishable_key' => 'pk_test_'.Str::random(24),
            'last_error' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
