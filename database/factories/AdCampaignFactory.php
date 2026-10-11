<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdCampaign;
use App\Models\AdPlatformAccount;
use App\Support\Advertising\AdCampaignStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdCampaign>
 */
class AdCampaignFactory extends Factory
{
    protected $model = AdCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad_platform_account_id' => AdPlatformAccount::factory(),
            'external_id' => fake()->unique()->numerify('##########'),
            'name' => 'Brand search',
            'status' => AdCampaignStatus::Enabled,
            'channel_type' => 'search',
            'site_id' => null,
            'unit_class_id' => null,
            'utm_campaign_hint' => null,
            'mapped_by' => null,
            'mapped_at' => null,
            'first_seen_at' => now(),
            'last_synced_at' => now(),
        ];
    }
}
