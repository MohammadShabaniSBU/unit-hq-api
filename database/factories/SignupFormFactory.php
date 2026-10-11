<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SignupForm;
use App\Support\Marketing\SignupFormPurpose;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignupForm>
 */
class SignupFormFactory extends Factory
{
    protected $model = SignupForm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_token' => Str::random(48),
            'name' => fake()->words(3, true),
            'purpose' => SignupFormPurpose::Subscribe,
            'enquiry_fields' => null,
            'site_id' => null,
            'subscription_list_ids' => [],
            'channel' => 'email',
            'fields' => [
                ['key' => 'first_name', 'required' => true],
                ['key' => 'last_name', 'required' => false],
            ],
            'consent_text_key' => 'newsletter_signup',
            'double_opt_in' => true,
            'confirmation_template_family_id' => null,
            'success_redirect_url' => null,
            'allowed_origins' => null,
            'archived_at' => null,
        ];
    }
}
