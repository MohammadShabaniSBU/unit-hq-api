<?php

namespace Database\Factories;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
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
            'status' => ContactLifecycleStatus::Prospect,
            'contact_status' => ContactRecordStatus::Active,
            'canonical_contact_id' => null,
            'source' => null,
            'source_detail' => null,
            'assigned_to' => null,
            'last_contacted_at' => null,
            'created_by' => null,
        ];
    }
}
