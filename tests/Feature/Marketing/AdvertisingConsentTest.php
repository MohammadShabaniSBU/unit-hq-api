<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\MarketingTouch;
use App\Models\SignupForm;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentRuleException;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use App\Support\Marketing\SignupFormPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AdvertisingConsentTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private Site $site;

    private ConsentText $formText;

    private SubscriptionList $advertising;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->formText = ConsentText::factory()->create([
            'key' => 'form_consent',
            'version' => 3,
            'locale' => 'en',
            'body' => 'I agree to this form.',
        ]);
        $this->advertising = SubscriptionList::query()->where('key', 'advertising')->firstOrFail();
    }

    #[Test]
    public function enquiry_with_ad_user_data_granted_writes_ads_consent(): void
    {
        $form = $this->enquiryForm();

        $this->submit($form, [
            'email' => 'granted@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
            't' => $this->touchParam([
                'consent' => [
                    'ad_storage' => false,
                    'ad_user_data' => 'granted',
                    'ad_personalization' => false,
                ],
            ]),
        ])->assertOk();

        $this->assertSame(0, MarketingTouch::query()->where('signup_form_id', $form->id)->count());

        $contact = Contact::query()->where('email', 'granted@example.com')->firstOrFail();
        $this->assertTrue(ConsentState::isGranted($contact->id, ConsentChannel::Ads, $this->advertising->id));

        $event = ConsentEvent::query()
            ->where('contact_id', $contact->id)
            ->where('channel', 'ads')
            ->firstOrFail();
        $this->assertSame(ConsentSource::Form, $event->source);
        $this->assertSame($form->id, $event->evidence['form_id'] ?? null);
        $this->assertTrue($event->evidence['consent_snapshot']['ad_user_data'] ?? false);
        $this->assertFalse($event->evidence['consent_snapshot']['ad_storage'] ?? true);
        $text = ConsentText::query()->findOrFail($event->consent_text_id);
        $this->assertSame('advertising', $text->key);
        $this->assertSame($text->version, $event->evidence['consent_text_version'] ?? null);
    }

    #[Test]
    public function enquiry_with_ad_user_data_denied_writes_no_ads_event(): void
    {
        $form = $this->enquiryForm();

        $this->submit($form, [
            'email' => 'denied@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
            't' => $this->touchParam([
                'gclid' => 'X',
                'consent' => [
                    'ad_storage' => true,
                    'ad_user_data' => 'denied',
                ],
            ]),
        ])->assertOk();

        $this->assertSame(1, MarketingTouch::query()->where('signup_form_id', $form->id)->count());
        $this->assertSame(0, ConsentEvent::query()->where('channel', 'ads')->count());
    }

    #[Test]
    public function signup_with_ad_user_data_granted_writes_ads_consent(): void
    {
        $list = SubscriptionList::factory()->create([
            'channels' => ['email'],
            'is_system' => false,
        ]);
        $form = SignupForm::factory()->create([
            'purpose' => SignupFormPurpose::Subscribe,
            'site_id' => $this->site->id,
            'subscription_list_ids' => [$list->id],
            'consent_text_key' => $this->formText->key,
            'double_opt_in' => false,
            'fields' => [],
        ]);

        $this->submit($form, [
            'email' => 'signup.ads@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
            't' => $this->touchParam([
                'consent' => [
                    'ad_storage' => true,
                    'ad_user_data' => true,
                ],
            ]),
        ])->assertOk();

        $contact = Contact::query()->where('email', 'signup.ads@example.com')->firstOrFail();
        $this->assertTrue(ConsentState::isGranted($contact->id, ConsentChannel::Ads, $this->advertising->id));
        $this->assertSame(1, ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'ads')->count());
    }

    #[Test]
    public function soft_opt_in_on_the_advertising_list_is_rejected(): void
    {
        $contact = Contact::factory()->create();
        $text = ConsentText::query()->where('key', 'advertising')->where('locale', 'en')->firstOrFail();

        try {
            ConsentWriter::grant(
                $contact,
                ConsentChannel::Ads,
                $this->advertising,
                ConsentSource::SoftOptIn,
                $text->id,
            );
            $this->fail('Soft opt-in on the advertising list must be rejected.');
        } catch (ConsentRuleException $exception) {
            $this->assertSame('soft_opt_in', $exception->errorKey);
        }

        $this->assertSame(0, ConsentEvent::query()->count());
    }

    #[Test]
    public function operator_grant_and_withdraw_on_ads_require_a_note(): void
    {
        $contact = Contact::factory()->create();
        $text = ConsentText::query()->where('key', 'advertising')->where('locale', 'en')->firstOrFail();
        $manager = $this->employeeWithPermission(Permission::ConsentManage);
        Sanctum::actingAs($manager);

        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'ads',
            'subscription_list_id' => $this->advertising->id,
            'action' => 'granted',
            'consent_text_id' => $text->id,
        ])->assertStatus(422);

        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'ads',
            'subscription_list_id' => $this->advertising->id,
            'action' => 'granted',
            'consent_text_id' => $text->id,
            'note' => 'Agreed on the paper form at the desk.',
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'ads')
            ->assertJsonPath('data.source', 'operator');

        $this->assertTrue(ConsentState::isGranted($contact->id, ConsentChannel::Ads, $this->advertising->id));

        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'ads',
            'subscription_list_id' => $this->advertising->id,
            'action' => 'withdrawn',
        ])->assertStatus(422);

        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'ads',
            'subscription_list_id' => $this->advertising->id,
            'action' => 'withdrawn',
            'note' => 'Asked to stop ad measurement.',
        ])->assertCreated();

        $this->assertFalse(ConsentState::isGranted($contact->id, ConsentChannel::Ads, $this->advertising->id));
    }

    private function enquiryForm(): SignupForm
    {
        return SignupForm::factory()->create([
            'purpose' => SignupFormPurpose::Enquiry,
            'site_id' => $this->site->id,
            'subscription_list_ids' => [],
            'consent_text_key' => $this->formText->key,
            'fields' => [
                ['key' => 'phone', 'required' => false],
            ],
            'double_opt_in' => false,
            'enquiry_fields' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(SignupForm $form, array $payload): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['User-Agent' => 'AdsConsentTest/1.0'])
            ->postJson('/api/public/forms/'.$form->public_token.'/submit', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function touchParam(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
