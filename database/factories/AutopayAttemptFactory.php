<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Models\AutopayAttempt;
use App\Models\Contract;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutopayAttempt>
 */
class AutopayAttemptFactory extends Factory
{
    protected $model = AutopayAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'charge_ids' => [],
            'amount' => '100.00',
            'currency' => 'EUR',
            'stripe_payment_intent_id' => null,
            'status' => AutopayAttemptStatus::Pending,
            'failure_code' => null,
            'decline_code' => null,
            'failure_message' => null,
            'triggered_by' => AutopayAttemptTrigger::Manual,
            'billing_run_id' => null,
            'attempted_at' => now(),
            'resolved_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => AutopayAttemptStatus::Succeeded,
            'resolved_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AutopayAttemptStatus::Failed,
            'failure_code' => 'card_declined',
            'decline_code' => 'generic_decline',
            'failure_message' => 'Your card was declined.',
            'resolved_at' => now(),
        ]);
    }
}
