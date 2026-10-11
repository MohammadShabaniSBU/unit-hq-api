<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketingTouch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketingTouch>
 */
class MarketingTouchFactory extends Factory
{
    protected $model = MarketingTouch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => null,
            'visitor_key' => Str::lower(Str::random(32)),
            'occurred_at' => now(),
            'site_id' => null,
            'platform' => null,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'brand',
            'utm_term' => null,
            'utm_content' => null,
            'click_ids' => ['gclid' => 'test-gclid'],
            'landing_url' => 'https://example.test/storage',
            'referrer_host' => 'www.google.com',
            'signup_form_id' => null,
            'consent_snapshot' => ['ad_storage' => true],
        ];
    }
}
