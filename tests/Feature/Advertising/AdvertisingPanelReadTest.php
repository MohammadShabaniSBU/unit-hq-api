<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Enums\LogChannel;
use App\Models\AdCampaign;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\MarketingTouch;
use App\Models\SignupForm;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionValueRule;
use App\Support\Auth\Permission;
use App\Support\Marketing\SignupFormPurpose;
use App\Support\RecordsActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AdvertisingPanelReadTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function setup_reports_checklist_booleans_without_secrets_or_click_ids(): void
    {
        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingView));

        $this->getJson('/api/advertising/setup')
            ->assertOk()
            ->assertJsonPath('data.accounts_connected', false)
            ->assertJsonPath('data.tag_seen', false)
            ->assertJsonPath('data.enquiry_form', false)
            ->assertJsonPath('data.mappings_set', true)
            ->assertJsonPath('data.campaigns_mapped', true)
            ->assertJsonPath('data.accounts', []);

        $archived = AdPlatformAccount::factory()->create([
            'name' => 'Archived',
            'archived_at' => now(),
        ]);
        $inactive = AdPlatformAccount::factory()->create([
            'name' => 'Paused account',
            'is_active' => false,
            'external_account_id' => '200',
        ]);
        $active = AdPlatformAccount::factory()->create([
            'name' => 'Live Ads',
            'currency' => 'EUR',
            'external_account_id' => '300',
            'credentials' => ['refresh_token' => 'secret-token'],
        ]);

        AdCampaign::factory()->create([
            'ad_platform_account_id' => $active->id,
            'status' => AdCampaignStatus::Enabled,
            'site_id' => null,
            'external_id' => 'enabled-unmapped',
        ]);

        MarketingTouch::factory()->create([
            'occurred_at' => now()->subDays(8),
            'click_ids' => ['gclid' => 'old-click'],
        ]);

        SignupForm::factory()->create([
            'purpose' => SignupFormPurpose::Enquiry,
            'archived_at' => now(),
        ]);

        $mid = $this->getJson('/api/advertising/setup')->assertOk();
        $mid->assertJsonPath('data.accounts_connected', true);
        $mid->assertJsonPath('data.tag_seen', false);
        $mid->assertJsonPath('data.enquiry_form', false);
        $mid->assertJsonPath('data.mappings_set', false);
        $mid->assertJsonPath('data.campaigns_mapped', false);
        $mid->assertJsonMissingPath('data.accounts.0.credentials');
        $this->assertStringNotContainsString('secret-token', $mid->getContent());
        $this->assertStringNotContainsString('old-click', $mid->getContent());

        $names = collect($mid->json('data.accounts'))->pluck('name')->all();
        $this->assertSame(['Live Ads', 'Paused account'], $names);
        $this->assertNotContains($archived->id, collect($mid->json('data.accounts'))->pluck('id')->all());

        foreach (ConversionEventKey::cases() as $key) {
            AdConversionMapping::factory()->create([
                'ad_platform_account_id' => $active->id,
                'event_key' => $key,
                'value_rule' => ConversionValueRule::None,
            ]);
        }

        $site = Site::factory()->create();
        AdCampaign::query()->where('external_id', 'enabled-unmapped')->update(['site_id' => $site->id]);
        MarketingTouch::factory()->create([
            'occurred_at' => now()->subDay(),
            'click_ids' => ['gclid' => 'fresh-click'],
        ]);
        SignupForm::factory()->create([
            'purpose' => SignupFormPurpose::Enquiry,
            'site_id' => $site->id,
        ]);

        $done = $this->getJson('/api/advertising/setup')->assertOk();
        $done->assertJsonPath('data.tag_seen', true);
        $done->assertJsonPath('data.enquiry_form', true);
        $done->assertJsonPath('data.mappings_set', true);
        $done->assertJsonPath('data.campaigns_mapped', true);
        $this->assertStringNotContainsString('fresh-click', $done->getContent());
    }

    #[Test]
    public function tag_probe_reports_presence_without_echoing_the_click_id(): void
    {
        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingView));
        $this->getJson('/api/advertising/tag-probe?gclid=secret-click')->assertForbidden();

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingConnect));

        $this->getJson('/api/advertising/tag-probe?gclid=secret-click')
            ->assertOk()
            ->assertJsonPath('data.found', false)
            ->assertJsonPath('data.occurred_at', null);

        $touch = MarketingTouch::factory()->create([
            'click_ids' => ['gclid' => 'secret-click'],
            'landing_url' => 'https://example.test/storage?gclid=secret-click',
            'occurred_at' => now()->subHour(),
        ]);

        $found = $this->getJson('/api/advertising/tag-probe?gclid=secret-click')->assertOk();
        $found->assertJsonPath('data.found', true);
        $found->assertJsonPath('data.occurred_at', $touch->occurred_at->toDateTimeString());
        $this->assertStringNotContainsString('secret-click', $found->getContent());
        $this->assertArrayNotHasKey('click_ids', $found->json('data'));

        $this->getJson('/api/advertising/tag-probe?gclid=other-click')
            ->assertOk()
            ->assertJsonPath('data.found', false);
    }

    #[Test]
    public function contact_touches_omit_click_ids_and_keep_the_landing_path(): void
    {
        $contact = Contact::factory()->create();
        $older = now()->subDays(2)->startOfSecond();
        $newer = now()->subDay()->startOfSecond();

        MarketingTouch::factory()->create([
            'contact_id' => $contact->id,
            'platform' => AdPlatform::GoogleAds,
            'utm_campaign' => 'spring',
            'occurred_at' => $older,
            'click_ids' => ['gclid' => 'secret-click'],
            'landing_url' => 'https://example.test/storage?gclid=secret-click',
            'visitor_key' => 'visitor-secret',
        ]);
        MarketingTouch::factory()->create([
            'contact_id' => $contact->id,
            'platform' => AdPlatform::Meta,
            'utm_campaign' => 'retarget',
            'occurred_at' => $newer,
            'click_ids' => ['fbclid' => 'fb-secret'],
            'landing_url' => 'https://example.test/contact',
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::ContactView));

        $response = $this->getJson('/api/contacts/'.$contact->id.'/marketing-touches')->assertOk();
        $response->assertJsonPath('data.0.platform', 'meta');
        $response->assertJsonPath('data.0.utm_campaign', 'retarget');
        $response->assertJsonPath('data.0.landing_path', '/contact');
        $response->assertJsonPath('data.0.occurred_at', $newer->toDateTimeString());
        $response->assertJsonPath('data.1.utm_campaign', 'spring');
        $response->assertJsonPath('data.1.landing_path', '/storage');
        $response->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('secret-click', $response->getContent());
        $this->assertStringNotContainsString('fb-secret', $response->getContent());
        $this->assertStringNotContainsString('visitor-secret', $response->getContent());
        $this->assertArrayNotHasKey('click_ids', $response->json('data.0'));
    }

    #[Test]
    public function deal_show_adds_acquired_via_without_a_click_id(): void
    {
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'created_at' => now(),
        ]);
        $account = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::GoogleAds,
        ]);
        AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'external_id' => '99',
            'name' => 'Spring search',
        ]);
        MarketingTouch::factory()->create([
            'contact_id' => $contact->id,
            'platform' => AdPlatform::GoogleAds,
            'utm_campaign' => '99',
            'occurred_at' => now()->subDay(),
            'click_ids' => ['gclid' => 'secret-click'],
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::DealManage));

        $response = $this->getJson('/api/deals/'.$deal->id)->assertOk();
        $response->assertJsonPath('data.acquired_via.platform', 'google_ads');
        $response->assertJsonPath('data.acquired_via.campaign_name', 'Spring search');
        $this->assertStringNotContainsString('secret-click', $response->getContent());
    }

    #[Test]
    public function account_resource_exposes_the_latest_committed_sync(): void
    {
        $account = AdPlatformAccount::factory()->create();
        $older = now()->subDay()->startOfSecond();
        $newer = now()->subHour()->startOfSecond();

        SystemEvent::query()->create([
            'event' => 'ads.sync.committed',
            'subject_type' => $account->getMorphClass(),
            'subject_id' => $account->id,
            'payload' => ['kind' => 'campaigns', 'count' => 1],
            'created_at' => $older,
        ]);
        SystemEvent::query()->create([
            'event' => 'ads.sync.failed',
            'subject_type' => $account->getMorphClass(),
            'subject_id' => $account->id,
            'payload' => ['kind' => 'spend'],
            'created_at' => $newer,
        ]);
        SystemEvent::query()->create([
            'event' => 'ads.sync.committed',
            'subject_type' => $account->getMorphClass(),
            'subject_id' => $account->id,
            'payload' => ['kind' => 'spend', 'count' => 2],
            'created_at' => $newer,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingConnect));

        $this->getJson('/api/ad-platform-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.last_synced_at', $newer->toDateTimeString());
    }

    #[Test]
    public function signup_form_payload_locks_purpose_and_counts_enquiry_outcomes_once(): void
    {
        $form = SignupForm::factory()->create([
            'purpose' => SignupFormPurpose::Enquiry,
        ]);
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['contact_id' => $contact->id]);

        RecordsActivity::log(LogChannel::Crm, 'deal.created', $deal, [
            'signup_form_id' => $form->id,
        ], anonymous: true);
        RecordsActivity::log(LogChannel::Crm, 'deal.created', $contact, [
            'signup_form_id' => $form->id,
            'deal_id' => $deal->id,
        ], anonymous: true);
        RecordsActivity::log(LogChannel::Crm, 'deal.web_enquiry', $deal, [
            'signup_form_id' => $form->id,
        ], anonymous: true);

        Sanctum::actingAs($this->employeeWithPermission(Permission::MarketingView));

        $this->getJson('/api/signup-forms/'.$form->id)
            ->assertOk()
            ->assertJsonPath('data.purpose_locked', true)
            ->assertJsonPath('data.enquiry_count', 2);
    }

    #[Test]
    public function campaign_index_filters_by_provider(): void
    {
        $google = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::GoogleAds,
            'external_account_id' => '111',
        ]);
        $meta = AdPlatformAccount::factory()->create([
            'provider' => AdPlatform::Meta,
            'external_account_id' => '222',
            'credentials' => [
                'system_user_token' => 'token',
                'capi_token' => 'capi',
                'pixel_id' => '333',
            ],
        ]);
        AdCampaign::factory()->create([
            'ad_platform_account_id' => $google->id,
            'name' => 'Google brand',
            'external_id' => 'g1',
        ]);
        AdCampaign::factory()->create([
            'ad_platform_account_id' => $meta->id,
            'name' => 'Meta brand',
            'external_id' => 'm1',
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::AdvertisingView));

        $this->getJson('/api/ad-campaigns?provider=meta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Meta brand')
            ->assertJsonPath('data.0.provider', 'meta');
    }
}
