<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\AdCampaign;
use App\Models\AdConversionDelivery;
use App\Models\AdConversionEvent;
use App\Models\AdPlatformAccount;
use App\Models\AdSpendDaily;
use App\Models\Contact;
use App\Models\MarketingTouch;
use App\Support\Advertising\AdDeliverySkipReason;
use App\Support\Advertising\AdDeliveryStatus;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\ConversionEventKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdAnalyticsViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Analytics views are Postgres-only.');
        }
    }

    #[Test]
    public function marketing_touches_view_omits_click_ids_and_landing_urls(): void
    {
        $contact = Contact::factory()->create();
        $touch = MarketingTouch::factory()->create([
            'contact_id' => $contact->id,
            'platform' => AdPlatform::GoogleAds,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'brand',
            'click_ids' => ['gclid' => 'secret-click'],
            'landing_url' => 'https://example.test/secret',
            'visitor_key' => 'visitor-secret-key-32chars-pad',
        ]);

        $row = DB::selectOne(
            'select * from analytics.v_marketing_touches where touch_id = ?',
            [$touch->id],
        );

        $this->assertNotNull($row);
        $this->assertSame($touch->id, (int) $row->touch_id);
        $this->assertSame($contact->id, (int) $row->contact_id);
        $this->assertSame('google_ads', $row->platform);
        $this->assertSame('brand', $row->utm_campaign);
        $this->assertObjectNotHasProperty('click_ids', $row);
        $this->assertObjectNotHasProperty('landing_url', $row);
        $this->assertObjectNotHasProperty('visitor_key', $row);
    }

    #[Test]
    public function spend_and_delivery_views_expose_currency_without_secrets(): void
    {
        $account = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::Meta,
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
        ]);
        $campaign = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'name' => 'Brand',
            'site_id' => null,
        ]);
        $spend = AdSpendDaily::factory()->create([
            'ad_campaign_id' => $campaign->id,
            'spend_date' => '2026-05-11',
            'cost' => '25.00',
            'currency' => 'GBP',
            'impressions' => 10,
            'clicks' => 2,
        ]);

        $spendRow = DB::selectOne(
            'select * from analytics.v_ad_spend_daily where campaign_id = ?',
            [$campaign->id],
        );
        $this->assertNotNull($spendRow);
        $this->assertSame('Brand', $spendRow->campaign_name);
        $this->assertSame('meta', $spendRow->provider);
        $this->assertNull($spendRow->site_id);
        $this->assertSame('25.00', number_format((float) $spendRow->cost, 2, '.', ''));
        $this->assertSame('GBP', $spendRow->currency);
        $this->assertSame('Europe/London', $spendRow->account_timezone);
        $this->assertObjectNotHasProperty('credentials', $spendRow);
        $this->assertSame($spend->id, $spend->id);

        $contact = Contact::factory()->create();
        $event = AdConversionEvent::factory()->create([
            'event_key' => ConversionEventKey::MoveIn,
            'contact_id' => $contact->id,
            'value' => '80.00',
            'currency' => 'EUR',
            'occurred_at' => '2026-05-26 12:00:00',
        ]);
        $delivery = AdConversionDelivery::factory()->create([
            'ad_conversion_event_id' => $event->id,
            'ad_platform_account_id' => $account->id,
            'status' => AdDeliveryStatus::Skipped,
            'skip_reason' => AdDeliverySkipReason::NoConsent,
            'processed_at' => '2026-05-26 12:05:00',
        ]);

        $deliveryRow = DB::selectOne(
            'select * from analytics.v_ad_conversion_deliveries where event_key = ? and provider = ?',
            [ConversionEventKey::MoveIn->value, AdPlatform::Meta->value],
        );
        $this->assertNotNull($deliveryRow);
        $this->assertSame('skipped', $deliveryRow->status);
        $this->assertSame('no_consent', $deliveryRow->skip_reason);
        $this->assertSame('80.00', number_format((float) $deliveryRow->value, 2, '.', ''));
        $this->assertSame('EUR', $deliveryRow->currency);
        $this->assertObjectNotHasProperty('identifiers_used', $deliveryRow);
        $this->assertObjectNotHasProperty('provider_response', $deliveryRow);
        $this->assertSame($delivery->id, $delivery->id);
    }
}
