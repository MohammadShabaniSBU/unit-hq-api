<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentRequestStatus;
use App\Models\Contract;
use App\Models\PaymentProviderAccount;
use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
{
    protected $model = PaymentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(64),
            'contract_id' => Contract::factory(),
            'payment_provider_account_id' => PaymentProviderAccount::factory()->connected(),
            'charge_ids' => [],
            'amount' => '100.00',
            'currency' => 'EUR',
            'status' => PaymentRequestStatus::Pending,
            'expires_at' => now()->addDays(7),
            'stripe_payment_intent_id' => null,
            'save_card_requested' => false,
            'paid_payment_id' => null,
            'created_by' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentRequestStatus::Pending,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentRequestStatus::Cancelled,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentRequestStatus::Paid,
        ]);
    }
}
