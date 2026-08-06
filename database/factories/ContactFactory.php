<?php

namespace Database\Factories;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
use App\Enums\TaxIdType;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'company' => fake()->boolean() ? fake()->company() : null,
            'billing_name' => null,
            'tax_id' => null,
            'tax_id_type' => null,
            'billing_address_line1' => null,
            'billing_address_line2' => null,
            'billing_city' => null,
            'billing_postal_code' => null,
            'billing_country_code' => null,
            'status' => ContactLifecycleStatus::Prospect,
            'contact_status' => ContactRecordStatus::Active,
            'canonical_contact_id' => null,
            'assigned_to' => null,
            'last_contacted_at' => null,
            'created_by' => null,
        ];
    }

    /** Full fiscal identity for ordinary-invoice tests. */
    public function fiscalComplete(): static
    {
        return $this->state(fn () => [
            'billing_name' => null,
            'tax_id' => '12345678Z',
            'tax_id_type' => TaxIdType::Nif,
            'billing_address_line1' => 'Calle Mayor 1',
            'billing_address_line2' => null,
            'billing_city' => 'Madrid',
            'billing_postal_code' => '28013',
            'billing_country_code' => 'ES',
        ]);
    }
}
