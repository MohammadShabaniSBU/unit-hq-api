<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Enums\ContactChannelType;
use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\AdConversionDelivery;
use App\Models\AdConversionEvent;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\MarketingTouch;
use App\Models\Site;
use App\Support\Advertising\AdDeliverySkipReason;
use App\Support\Advertising\AdDeliveryStatus;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\ConversionDelivery;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionOutbox;
use App\Support\Advertising\ConversionValueRule;
use App\Support\Auth\Permission;
use App\Support\Marketing\AdvertisingConsent;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class ConversionDeliveryTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private const EMAIL = 'pii-email@example.com';

    private const PHONE = '+34600111222';

    private const GCLID = 'pii-gclid-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29-access'], 200, ['request-id' => 'req-1']),
            'https://googleads.googleapis.com/*' => Http::response(['results' => []], 200, ['request-id' => 'req-1']),
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200, ['x-fb-trace-id' => 'fb-1']),
        ]);
    }

    #[Test]
    public function the_job_and_the_sweeper_send_each_account_event_once(): void
    {
        $contact = $this->consentedContact();
        $this->googleAccount(ConversionEventKey::Lead);
        $this->touch($contact, AdPlatform::GoogleAds, ['gclid' => self::GCLID]);
        $deal = Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => Site::factory()->create()->id]);

        app(ConversionDelivery::class)->run();
        Artisan::call('ads:deliver-conversions');

        $uploads = $this->recorded('uploadClickConversions');
        $this->assertCount(1, $uploads);
        $body = $uploads[0]->data();
        $this->assertTrue($body['partialFailure']);
        $this->assertSame('deal:'.$deal->id.':lead', $body['conversions'][0]['orderId']);
        $this->assertArrayNotHasKey('conversionValue', $body['conversions'][0]);

        $delivery = AdConversionDelivery::query()->firstOrFail();
        $this->assertSame(AdDeliveryStatus::Sent, $delivery->status);
        $this->assertSame(['gclid', 'hashed_email'], $delivery->identifiers_used);
    }

    #[Test]
    public function withdrawn_ads_consent_skips_no_consent_and_sends_nothing(): void
    {
        Bus::fake();
        $contact = $this->consentedContact();
        $this->googleAccount(ConversionEventKey::Lead);
        $this->touch($contact, AdPlatform::GoogleAds, ['gclid' => self::GCLID]);
        Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => Site::factory()->create()->id]);

        ConsentWriter::withdraw(
            $contact,
            ConsentChannel::Ads,
            AdvertisingConsent::list(),
            ConsentSource::PreferenceCenter,
        );

        app(ConversionDelivery::class)->run();

        $this->assertSame([], $this->recorded('uploadClickConversions'));
        $delivery = AdConversionDelivery::query()->firstOrFail();
        $this->assertSame(AdDeliveryStatus::Skipped, $delivery->status);
        $this->assertSame(AdDeliverySkipReason::NoConsent, $delivery->skip_reason);
    }

    #[Test]
    public function google_without_a_click_id_skips_and_meta_sends_on_email(): void
    {
        $contact = $this->consentedContact();
        $this->googleAccount(ConversionEventKey::Lead);
        $meta = $this->metaAccount(ConversionEventKey::Lead, 'Lead');
        $this->touch($contact, AdPlatform::Meta, []);
        $deal = Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => Site::factory()->create()->id]);

        app(ConversionDelivery::class)->run();

        $google = AdConversionDelivery::query()->where('ad_platform_account_id', '!=', $meta->id)->firstOrFail();
        $metaDelivery = AdConversionDelivery::query()->where('ad_platform_account_id', $meta->id)->firstOrFail();

        $this->assertSame(AdDeliveryStatus::Skipped, $google->status);
        $this->assertSame(AdDeliverySkipReason::NoIdentifier, $google->skip_reason);
        $this->assertSame(AdDeliveryStatus::Sent, $metaDelivery->status);
        $this->assertSame(['em', 'external_id'], $metaDelivery->identifiers_used);

        $posts = $this->recorded('/events');
        $this->assertCount(1, $posts);
        $event = $posts[0]->data()['data'][0];
        $this->assertSame('deal:'.$deal->id.':lead', $event['event_id']);
        $this->assertSame('system_generated', $event['action_source']);
        $this->assertSame('Lead', $event['event_name']);
        $this->assertArrayNotHasKey('custom_data', $event);
        $this->assertSame([], $this->recorded('uploadClickConversions'));
    }

    #[Test]
    public function delivery_rows_store_identifier_names_only(): void
    {
        $contact = $this->consentedContact();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => self::PHONE,
            'is_primary' => true,
            'opted_in' => true,
        ]);
        $this->googleAccount(ConversionEventKey::Lead);
        $this->touch($contact, AdPlatform::GoogleAds, ['gclid' => self::GCLID]);
        Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => Site::factory()->create()->id]);

        app(ConversionDelivery::class)->run();

        $row = DB::table('ad_conversion_deliveries')->first();
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::EMAIL, $encoded);
        $this->assertStringNotContainsString(self::PHONE, $encoded);
        $this->assertStringNotContainsString('34600111222', $encoded);
        $this->assertStringNotContainsString(self::GCLID, $encoded);

        $delivery = AdConversionDelivery::query()->firstOrFail();
        $this->assertSame(['gclid', 'hashed_email', 'hashed_phone_number'], $delivery->identifiers_used);
    }

    #[Test]
    public function move_in_payloads_carry_value_and_the_dedupe_id(): void
    {
        $contact = $this->consentedContact();
        $google = $this->googleAccount(ConversionEventKey::MoveIn, ConversionValueRule::FirstPeriodNet);
        $meta = $this->metaAccount(ConversionEventKey::MoveIn, 'Purchase', ConversionValueRule::FirstPeriodNet);
        $this->touch($contact, AdPlatform::GoogleAds, ['gclid' => self::GCLID], adPersonalization: false);
        $this->touch($contact, AdPlatform::Meta, ['fbc' => 'fb.1.1.pii']);
        $site = Site::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
        ]);

        ConversionOutbox::record(
            ConversionEventKey::MoveIn,
            $contract,
            $contact,
            $site,
            '12.50',
            'EUR',
            CarbonImmutable::now()->subHour(),
        );

        app(ConversionDelivery::class)->run();

        $upload = $this->recorded('uploadClickConversions')[0]->data()['conversions'][0];
        $this->assertSame('contract:'.$contract->id.':move_in', $upload['orderId']);
        $this->assertSame('12.50', $upload['conversionValue']);
        $this->assertSame('EUR', $upload['currencyCode']);
        $this->assertSame('GRANTED', $upload['consent']['adUserData']);
        $this->assertSame('DENIED', $upload['consent']['adPersonalization']);
        $this->assertSame(self::GCLID, $upload['gclid']);

        $metaEvent = $this->recorded('/events')[0]->data()['data'][0];
        $this->assertSame('contract:'.$contract->id.':move_in', $metaEvent['event_id']);
        $this->assertSame('Purchase', $metaEvent['event_name']);
        $this->assertSame('system_generated', $metaEvent['action_source']);
        $this->assertSame('12.50', $metaEvent['custom_data']['value']);
        $this->assertSame('EUR', $metaEvent['custom_data']['currency']);

        $this->assertNotNull(AdConversionDelivery::query()->where('ad_platform_account_id', $google->id)->where('status', 'sent')->first());
        $this->assertNotNull(AdConversionDelivery::query()->where('ad_platform_account_id', $meta->id)->where('status', 'sent')->first());
    }

    #[Test]
    public function retry_works_only_from_failed(): void
    {
        Bus::fake();
        $manager = $this->employeeWithPermission(Permission::AdvertisingManage);
        Sanctum::actingAs($manager);

        $failed = AdConversionDelivery::factory()->create([
            'status' => AdDeliveryStatus::Failed,
            'attempts' => 5,
            'error' => 'partial_failure',
        ]);
        $sent = AdConversionDelivery::factory()->create([
            'status' => AdDeliveryStatus::Sent,
            'attempts' => 1,
        ]);
        $skipped = AdConversionDelivery::factory()->create([
            'status' => AdDeliveryStatus::Skipped,
            'skip_reason' => AdDeliverySkipReason::NoConsent,
        ]);

        $this->postJson('/api/ad-conversion-deliveries/'.$failed->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempts', 0);

        $failed->refresh();
        $this->assertSame(AdDeliveryStatus::Pending, $failed->status);
        $this->assertSame(0, $failed->attempts);
        $this->assertNull($failed->error);

        $this->assertNotNull(Activity::query()->where('description', 'ads.delivery.retried')->where('log_name', LogChannel::Ads->value)->first());

        $this->postJson('/api/ad-conversion-deliveries/'.$sent->id.'/retry')->assertStatus(422);
        $this->postJson('/api/ad-conversion-deliveries/'.$skipped->id.'/retry')->assertStatus(422);
        $this->assertSame(AdDeliveryStatus::Sent, $sent->fresh()->status);
    }

    #[Test]
    public function mappings_upsert_and_a_test_conversion_writes_nothing(): void
    {
        $account = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::Meta,
            'credentials' => [
                'system_user_token' => 'system-token',
                'capi_token' => 'capi-token',
                'pixel_id' => '555',
            ],
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingManage));

        $this->putJson('/api/ad-platform-accounts/'.$account->id.'/conversion-mappings', [
            'mappings' => [
                ['event_key' => 'lead', 'external_ref' => 'Lead', 'value_rule' => 'none', 'is_active' => true],
                ['event_key' => 'reservation', 'external_ref' => 'Schedule', 'value_rule' => 'none', 'is_active' => true],
                ['event_key' => 'move_in', 'external_ref' => 'Purchase', 'value_rule' => 'first_period_net', 'is_active' => true],
            ],
        ])->assertOk()->assertJsonPath('data.mappings.2.external_ref', 'Purchase');

        $this->assertGreaterThanOrEqual(1, Activity::query()->where('description', 'ads.conversion_mapping.updated')->count());

        $eventsBefore = AdConversionEvent::query()->count();
        $deliveriesBefore = AdConversionDelivery::query()->count();

        $this->postJson('/api/ad-platform-accounts/'.$account->id.'/test-conversion', [
            'event_key' => 'move_in',
            'test_event_code' => 'TEST123',
        ])->assertOk()->assertJsonPath('data.ok', true);

        $this->assertSame($eventsBefore, AdConversionEvent::query()->count());
        $this->assertSame($deliveriesBefore, AdConversionDelivery::query()->count());

        $post = $this->recorded('/events')[0];
        $this->assertStringContainsString('test_event_code=TEST123', $post->url());
        $this->assertTrue($post->data()['data'][0]['event_id'] !== '');

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingView));
        $this->putJson('/api/ad-platform-accounts/'.$account->id.'/conversion-mappings', [
            'mappings' => [
                ['event_key' => 'lead', 'external_ref' => 'Lead', 'value_rule' => 'none', 'is_active' => true],
                ['event_key' => 'reservation', 'external_ref' => 'Schedule', 'value_rule' => 'none', 'is_active' => true],
                ['event_key' => 'move_in', 'external_ref' => 'Purchase', 'value_rule' => 'first_period_net', 'is_active' => true],
            ],
        ])->assertForbidden();

        $this->getJson('/api/ad-conversion-deliveries')->assertOk();
        $this->getJson('/api/ad-conversion-deliveries/summary')->assertOk();
    }

    #[Test]
    public function a_claimed_row_cannot_be_claimed_again(): void
    {
        Bus::fake();
        $contact = $this->consentedContact();
        $account = $this->googleAccount(ConversionEventKey::Lead);
        $this->touch($contact, AdPlatform::GoogleAds, ['gclid' => self::GCLID]);
        Deal::factory()->create(['contact_id' => $contact->id, 'site_id' => Site::factory()->create()->id]);

        $event = AdConversionEvent::query()->firstOrFail();
        $delivery = AdConversionDelivery::factory()->create([
            'ad_conversion_event_id' => $event->id,
            'ad_platform_account_id' => $account->id,
            'status' => AdDeliveryStatus::Claimed,
            'claimed_at' => now(),
            'attempts' => 1,
        ]);

        app(ConversionDelivery::class)->run();

        $this->assertSame([], $this->recorded('uploadClickConversions'));
        $this->assertSame(AdDeliveryStatus::Claimed, $delivery->fresh()->status);
    }

    private function consentedContact(): Contact
    {
        $contact = Contact::factory()->create(['email' => self::EMAIL]);
        $list = AdvertisingConsent::list();
        $this->assertNotNull($list);
        $text = ConsentText::query()->where('key', 'advertising')->where('locale', 'en')->firstOrFail();
        ConsentWriter::grant($contact, ConsentChannel::Ads, $list, ConsentSource::Form, $text->id);

        return $contact;
    }

    private function googleAccount(
        ConversionEventKey $key,
        ConversionValueRule $rule = ConversionValueRule::None,
    ): AdPlatformAccount {
        $account = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::GoogleAds,
            'external_account_id' => '1234567890',
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'credentials' => ['refresh_token' => 'refresh-token'],
            'is_active' => true,
        ]);
        AdConversionMapping::factory()->create([
            'ad_platform_account_id' => $account->id,
            'event_key' => $key,
            'external_ref' => 'customers/1234567890/conversionActions/9',
            'value_rule' => $rule,
            'is_active' => true,
        ]);

        return $account;
    }

    private function metaAccount(
        ConversionEventKey $key,
        string $ref,
        ConversionValueRule $rule = ConversionValueRule::None,
    ): AdPlatformAccount {
        $account = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::Meta,
            'external_account_id' => '99',
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'credentials' => [
                'system_user_token' => 'system-token',
                'capi_token' => 'capi-token',
                'pixel_id' => '555',
            ],
            'is_active' => true,
        ]);
        AdConversionMapping::factory()->create([
            'ad_platform_account_id' => $account->id,
            'event_key' => $key,
            'external_ref' => $ref,
            'value_rule' => $rule,
            'is_active' => true,
        ]);

        return $account;
    }

    /**
     * @param  array<string, string>  $clickIds
     */
    private function touch(Contact $contact, AdPlatform $platform, array $clickIds, bool $adPersonalization = true): MarketingTouch
    {
        return MarketingTouch::factory()->create([
            'contact_id' => $contact->id,
            'platform' => $platform,
            'occurred_at' => now()->subDay(),
            'click_ids' => $clickIds,
            'consent_snapshot' => [
                'ad_storage' => true,
                'ad_user_data' => true,
                'ad_personalization' => $adPersonalization,
            ],
        ]);
    }

    /**
     * @return list<Request>
     */
    private function recorded(string $needle): array
    {
        $requests = [];
        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains($request->url(), $needle)) {
                $requests[] = $request;
            }
        }

        return $requests;
    }
}
