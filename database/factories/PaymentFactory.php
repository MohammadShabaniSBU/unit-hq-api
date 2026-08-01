<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'amount' => fake()->randomFloat(2, 50, 300),
            'currency' => 'EUR',
            'method' => null,
            'received_on' => null,
            'reference' => null,
            'stripe_payment_intent_id' => 'pi_'.Str::random(24),
            'idempotency_key' => (string) Str::uuid(),
            'reversal_of_payment_id' => null,
        ];
    }

    public function cash(?string $receivedOn = null): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Cash,
            'received_on' => $receivedOn ?? now()->toDateString(),
            'stripe_payment_intent_id' => null,
            'idempotency_key' => 'manual:'.Str::uuid(),
        ]);
    }
}
