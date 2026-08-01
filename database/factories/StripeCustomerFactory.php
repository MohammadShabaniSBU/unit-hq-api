<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\PaymentProviderAccount;
use App\Models\StripeCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripeCustomer>
 */
class StripeCustomerFactory extends Factory
{
    protected $model = StripeCustomer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'payment_provider_account_id' => PaymentProviderAccount::factory()->connected(),
            'stripe_customer_id' => 'cus_'.Str::random(14),
        ];
    }
}
