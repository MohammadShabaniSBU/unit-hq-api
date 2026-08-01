<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentInstrumentType;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'type' => PaymentInstrumentType::StripeCard,
            'sepa_mandate_id' => null,
            'stripe_pm_id' => 'pm_'.Str::random(14),
            'payment_provider_account_id' => PaymentProviderAccount::factory()->connected(),
            'display_label' => 'Visa ···4242',
            'is_default' => false,
            'archived_at' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
            'is_default' => false,
        ]);
    }
}
