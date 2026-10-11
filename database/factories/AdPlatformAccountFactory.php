<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdPlatformAccount;
use App\Support\Advertising\AdPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdPlatformAccount>
 */
class AdPlatformAccountFactory extends Factory
{
    protected $model = AdPlatformAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AdPlatform::GoogleAds,
            'name' => 'Google Ads',
            'external_account_id' => fake()->unique()->numerify('##########'),
            'login_customer_id' => null,
            'currency' => null,
            'timezone' => null,
            'credentials' => ['refresh_token' => 'test-token'],
            'is_active' => true,
            'last_verified_at' => null,
            'last_error' => null,
            'archived_at' => null,
        ];
    }
}
