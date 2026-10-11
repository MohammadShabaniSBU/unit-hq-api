<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\MarketingTouch;
use App\Models\SignupForm;
use App\Models\Site;
use App\Support\Advertising\AdPlatform;
use App\Support\Auth\Permission;
use App\Support\Marketing\SignupFormPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class EnquiryFormTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private Site $site;

    private ConsentText $text;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->text = ConsentText::factory()->create([
            'key' => 'enquiry_consent',
            'version' => 1,
            'locale' => 'en',
            'body' => 'I agree to this enquiry being processed.',
        ]);
    }

    #[Test]
    public function an_enquiry_from_a_new_email_creates_a_contact_and_one_deal(): void
    {
        $form = $this->form();

        $this->submit($form, [
            'email' => 'new.enquiry@example.com',
            'first_name' => 'Nia',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ])->assertOk()->assertJsonPath('data.status', 'accepted');

        $contact = Contact::query()->where('email', 'new.enquiry@example.com')->firstOrFail();
        $this->assertSame(ContactSource::EnquiryForm, $contact->source);
        $this->assertSame(ContactLifecycleStatus::Prospect, $contact->status);

        $deals = Deal::query()->where('contact_id', $contact->id)->get();
        $this->assertCount(1, $deals);
        $this->assertSame($this->site->id, $deals[0]->site_id);
        $this->assertSame(DealStatus::New, $deals[0]->status);
    }

    #[Test]
    public function second_enquiry_same_site_adds_note_not_deal(): void
    {
        $form = $this->form();
        $payload = [
            'email' => 'again@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ];

        $this->submit($form, $payload)->assertOk();
        $this->submit($form, $payload, '203.0.113.11')->assertOk();

        $contact = Contact::query()->where('email', 'again@example.com')->firstOrFail();
        $deals = Deal::query()->where('contact_id', $contact->id)->get();
        $this->assertCount(1, $deals);
        $note = $deals[0]->notes()->where('content', 'deal.web_enquiry')->first();
        $this->assertNotNull($note);
        $this->assertNull($note->employee_id);
    }

    #[Test]
    public function a_different_site_creates_a_second_deal(): void
    {
        $form = $this->form();
        $other = Site::factory()->create();
        $otherForm = $this->form(['site_id' => $other->id]);
        $payload = [
            'email' => 'two.sites@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ];

        $this->submit($form, $payload)->assertOk();
        $this->submit($otherForm, $payload, '203.0.113.12')->assertOk();

        $contact = Contact::query()->where('email', 'two.sites@example.com')->firstOrFail();
        $siteIds = Deal::query()->where('contact_id', $contact->id)->pluck('site_id')->all();
        sort($siteIds);
        $expected = [$this->site->id, $other->id];
        sort($expected);
        $this->assertSame($expected, $siteIds);
    }

    #[Test]
    public function enquiry_form_requires_site(): void
    {
        $manager = $this->employeeWithPermission(Permission::MarketingManage);
        Sanctum::actingAs($manager);

        $this->postJson('/api/signup-forms', [
            'name' => 'Web enquiry',
            'purpose' => 'enquiry',
            'site_id' => null,
            'subscription_list_ids' => [],
            'fields' => [],
            'consent_text_key' => $this->text->key,
            'double_opt_in' => false,
        ])->assertStatus(422)
            ->assertJsonPath('errors.site_id.0', __('errors.signup_forms.enquiry_site_required'));
    }

    #[Test]
    public function changing_purpose_after_a_submission_returns_409(): void
    {
        $form = $this->form();
        $this->submit($form, [
            'email' => 'locked@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ])->assertOk();

        $manager = $this->employeeWithPermission(Permission::MarketingManage);
        Sanctum::actingAs($manager);

        $this->putJson('/api/signup-forms/'.$form->id, [
            'purpose' => 'subscribe',
        ])->assertStatus(409)
            ->assertJsonPath('message', __('errors.signup_forms.purpose_locked'));
    }

    #[Test]
    public function a_submission_without_ad_storage_writes_no_touch(): void
    {
        $form = $this->form();

        $this->submit($form, [
            'email' => 'no.consent@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
            't' => $this->touchParam([
                'gclid' => 'X',
                'utm_campaign' => 'spring',
                'landing_url' => 'https://operator.test/?gclid=X&utm_campaign=spring',
                'consent' => ['ad_storage' => false],
            ]),
        ])->assertOk();

        $this->assertSame(0, MarketingTouch::query()->where('signup_form_id', $form->id)->count());
    }

    #[Test]
    public function a_gclid_touch_is_stored_without_the_click_id_in_the_landing_url(): void
    {
        $form = $this->form();
        $visitor = str_repeat('a', 32);
        $prior = MarketingTouch::factory()->create([
            'visitor_key' => $visitor,
            'contact_id' => null,
        ]);

        $this->submit($form, [
            'email' => 'click@example.com',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
            't' => $this->touchParam([
                'visitor_key' => $visitor,
                'occurred_at' => now()->subHour()->getTimestampMs(),
                'landing_url' => 'https://operator.test/?gclid=X&utm_campaign=spring',
                'referrer' => 'https://www.google.com/aclk',
                'gclid' => 'X',
                'utm_campaign' => 'spring',
                'consent' => [
                    'ad_storage' => true,
                    'ad_user_data' => true,
                    'ad_personalization' => false,
                ],
            ]),
        ])->assertOk();

        $touch = MarketingTouch::query()->where('signup_form_id', $form->id)->firstOrFail();
        $this->assertSame('X', $touch->click_ids['gclid'] ?? null);
        $this->assertSame(AdPlatform::GoogleAds, $touch->platform);
        $this->assertSame('spring', $touch->utm_campaign);
        $this->assertStringNotContainsString('gclid', (string) $touch->landing_url);

        $contact = Contact::query()->where('email', 'click@example.com')->firstOrFail();
        $this->assertSame($contact->id, $prior->fresh()->contact_id);
    }

    #[Test]
    public function public_tag_is_cached_javascript(): void
    {
        $response = $this->get('/api/public/tag.js');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/javascript',
            (string) $response->headers->get('Content-Type'),
        );
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
        $this->assertNotSame('', (string) $response->headers->get('ETag'));
        $this->assertStringContainsString('keevaris', $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function form(array $overrides = []): SignupForm
    {
        return SignupForm::factory()->create([
            'purpose' => SignupFormPurpose::Enquiry,
            'site_id' => $this->site->id,
            'subscription_list_ids' => [],
            'consent_text_key' => $this->text->key,
            'fields' => [
                ['key' => 'phone', 'required' => false],
            ],
            'double_opt_in' => false,
            'enquiry_fields' => null,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(SignupForm $form, array $payload, string $ip = '203.0.113.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['User-Agent' => 'EnquiryTest/1.0'])
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
