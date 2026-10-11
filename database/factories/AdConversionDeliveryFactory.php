<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdConversionDelivery;
use App\Models\AdConversionEvent;
use App\Models\AdPlatformAccount;
use App\Support\Advertising\AdDeliveryStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdConversionDelivery>
 */
class AdConversionDeliveryFactory extends Factory
{
    protected $model = AdConversionDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad_conversion_event_id' => AdConversionEvent::factory(),
            'ad_platform_account_id' => AdPlatformAccount::factory(),
            'marketing_touch_id' => null,
            'status' => AdDeliveryStatus::Pending,
            'skip_reason' => null,
            'identifiers_used' => [],
            'attempts' => 0,
            'claimed_at' => null,
            'processed_at' => null,
            'error' => null,
            'provider_response' => null,
        ];
    }
}
