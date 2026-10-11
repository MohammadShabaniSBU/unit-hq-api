<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionValueRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdConversionMapping>
 */
class AdConversionMappingFactory extends Factory
{
    protected $model = AdConversionMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad_platform_account_id' => AdPlatformAccount::factory(),
            'event_key' => ConversionEventKey::Lead,
            'external_ref' => 'customers/1/conversionActions/2',
            'value_rule' => ConversionValueRule::None,
            'is_active' => true,
        ];
    }
}
