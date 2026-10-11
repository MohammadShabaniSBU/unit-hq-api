<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdSpendDaily>
 */
class AdSpendDailyFactory extends Factory
{
    protected $model = AdSpendDaily::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad_campaign_id' => AdCampaign::factory(),
            'spend_date' => now()->toDateString(),
            'impressions' => 0,
            'clicks' => 0,
            'cost' => '0.00',
            'currency' => 'EUR',
            'synced_at' => now(),
        ];
    }
}
