<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactSource;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\SignupConfirmation;
use App\Models\SignupForm;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentState;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class SignupFormTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    private Site $site;

    private SubscriptionList $list;

    private ConsentText $text;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->list = SubscriptionList::factory()->create([
            'channels' => ['email'],
            'labels' => ['en' => 'Newsletter', 'es' => 'Boletín', 'fr' => 'Infolettre'],
        ]);
        $this->text = ConsentText::factory()->create([
            'key' => 'newsletter_signup',
            'version' => 2,
            'locale' => 'en',
            'body' => 'I agree to receive the newsletter.',
        ]);
    }

    #[Test]
    public function double_opt_in_stays_pending_until_the_link_is_confirmed(): void
    {
        $this->fakeBrevo();
        $this->seedEmailAccount($this->site);
        $form = $this->form();

        $response = $this->submit($form, [
            'email' => 'new.person@example.com',
            'first_name' => 'Nia',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'accepted');

        $contact = Contact::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertSame(ContactSource::SignupForm, $contact->source);
        $this->assertSame(ContactLifecycleStatus::Prospect, $contact->status);
        $this->assertSame(0, Deal::query()->where('contact_id', $contact->id)->count());
        $this->assertTrue($contact->sites()->where('sites.id', $this->site->id)->exists());
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->list->id));

        $pending = ConsentEvent::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(ConsentAction::Pending, $pending->action);
        $this->assertSame($this->text->version, $pending->evidence['consent_text_version']);
        $this->assertSame($form->id, $pending->evidence['form_id']);
        $this->assertSame('SignupTest/1.0', $pending->evidence['user_agent']);
        $this->assertNotSame('', $pending->evidence['ip']);

        $token = $this->confirmationTokenFromMail();
        $confirm = $this->getJson('/api/public/forms/confirm/'.$token);
        $confirm->assertOk();
        $confirm->assertJsonPath('data.status', 'confirmed');
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->list->id));

        $again = $this->getJson('/api/public/forms/confirm/'.$token);
        $again->assertOk();
        $again->assertJsonPath('data.status', 'already_confirmed');
        $this->assertSame(1, ConsentEvent::query()
            ->where('contact_id', $contact->id)
            ->where('action', ConsentAction::Granted->value)
            ->count());
    }

    #[Test]
    public function confirmation_email_uses_the_system_template_confirm_url(): void
    {
        $this->fakeBrevo();
        $this->seedEmailAccount($this->site);

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Signup confirm',
            'purpose' => TemplatePurpose::System,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Please confirm',
            'legacy_html' => '<p>Confirm {{confirm_url}}</p>',
            'blocks' => null,
        ]]);

        $form = $this->form(['confirmation_template_family_id' => $family->id]);
        $this->submit($form, [
            'email' => 'template.person@example.com',
            'first_name' => 'Nia',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ])->assertOk();

        $token = $this->confirmationTokenFromMail();
        Http::assertSent(function (Request $request) use ($token): bool {
            $data = $request->data();

            return ($data['subject'] ?? null) === 'Please confirm'
                && str_contains((string) ($data['htmlContent'] ?? ''), '/f/confirm/'.$token);
        });
    }

    #[Test]
    public function resubmitting_an_existing_email_does_not_duplicate_the_contact(): void
    {
        $this->fakeBrevo();
        $this->seedEmailAccount($this->site);
        $form = $this->form();
        $existing = Contact::factory()->create([
            'email' => 'ada@example.com',
            'first_name' => 'Ada',
            'source' => null,
        ]);

        $repeat = $this->submit($form, [
            'email' => 'Ada@example.com',
            'first_name' => 'Someone',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ]);
        $fresh = $this->submit($form, [
            'email' => 'brand.new@example.com',
            'first_name' => 'Bea',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ]);

        $repeat->assertOk();
        $fresh->assertOk();
        $this->assertSame($repeat->json(), $fresh->json());
        $this->assertSame(1, Contact::query()->whereRaw('lower(email) = ?', ['ada@example.com'])->count());
        $this->assertSame('Ada', $existing->fresh()->first_name);
        $this->assertNull($existing->fresh()->source);
        $this->assertSame(ContactSource::SignupForm, Contact::query()->where('email', 'brand.new@example.com')->firstOrFail()->source);
    }

    #[Test]
    public function honeypot_and_too_fast_submits_are_dropped(): void
    {
        $form = $this->form(['double_opt_in' => false, 'site_id' => null]);
        $before = Contact::query()->count();

        $honeypot = $this->submit($form, [
            'email' => 'bot@example.com',
            'first_name' => 'Bot',
            'website' => 'https://spam.test',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ]);
        $fast = $this->submit($form, [
            'email' => 'fast@example.com',
            'first_name' => 'Fast',
            'opened_at' => now()->getTimestamp(),
        ]);

        $honeypot->assertOk();
        $honeypot->assertJsonPath('data.status', 'accepted');
        $fast->assertJsonPath('data.status', 'accepted');
        $this->assertSame($honeypot->json(), $fast->json());
        $this->assertSame($before, Contact::query()->count());
        $this->assertSame(0, ConsentEvent::query()->count());
    }

    #[Test]
    public function submit_is_rate_limited_per_ip(): void
    {
        $form = $this->form(['double_opt_in' => false, 'site_id' => null]);
        $payload = [
            'email' => 'limited@example.com',
            'first_name' => 'Lim',
            'website' => 'https://spam.test',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ];

        $last = null;
        for ($i = 0; $i < 10; $i++) {
            $last = $this->submit($form, $payload, '198.51.100.20');
            $last->assertOk();
        }

        $blocked = $this->submit($form, $payload, '198.51.100.20');
        $blocked->assertStatus(429);
        $this->assertSame(0, Contact::query()->where('email', 'limited@example.com')->count());
        $this->assertNotNull($last);
    }

    #[Test]
    public function expired_confirmation_asks_to_sign_up_again(): void
    {
        $this->fakeBrevo();
        $this->seedEmailAccount($this->site);
        $form = $this->form();

        $this->submit($form, [
            'email' => 'late@example.com',
            'first_name' => 'Late',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ])->assertOk();

        $token = $this->confirmationTokenFromMail();
        $this->travel(8)->days();

        $response = $this->getJson('/api/public/forms/confirm/'.$token);
        $response->assertOk();
        $response->assertJsonPath('data.status', 'expired');
        $response->assertJsonPath('message', 'Please sign up again.');

        $contact = Contact::query()->where('email', 'late@example.com')->firstOrFail();
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->list->id));
        $this->assertNull(SignupConfirmation::query()->where('contact_id', $contact->id)->firstOrFail()->confirmed_at);
    }

    #[Test]
    public function single_opt_in_grants_immediately(): void
    {
        $form = $this->form(['double_opt_in' => false, 'site_id' => null]);

        $this->submit($form, [
            'email' => 'once@example.com',
            'first_name' => 'Once',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ])->assertOk();

        $contact = Contact::query()->where('email', 'once@example.com')->firstOrFail();
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->list->id));
        $this->assertSame(0, SignupConfirmation::query()->count());
        $this->assertSame(0, Deal::query()->where('contact_id', $contact->id)->count());
    }

    #[Test]
    public function public_show_returns_the_consent_text_and_admin_counts_submissions(): void
    {
        $form = $this->form(['double_opt_in' => false, 'site_id' => null]);

        $show = $this->getJson('/api/public/forms/'.$form->public_token.'?locale=en');
        $show->assertOk();
        $show->assertJsonPath('data.consent_text.body', 'I agree to receive the newsletter.');
        $show->assertJsonPath('data.consent_text.version', 2);
        $show->assertJsonPath('data.lists.0.label', 'Newsletter');

        $viewer = $this->employeeWithPermission(Permission::MarketingView);
        $manager = $this->employeeWithPermission(Permission::MarketingManage);

        Sanctum::actingAs($viewer);
        $this->getJson('/api/signup-forms')->assertOk();
        $this->postJson('/api/signup-forms', [])->assertForbidden();

        $this->submit($form, [
            'email' => 'counted@example.com',
            'first_name' => 'Count',
            'opened_at' => now()->subSeconds(10)->getTimestamp(),
        ])->assertOk();

        $index = $this->getJson('/api/signup-forms');
        $index->assertOk();
        $index->assertJsonPath('data.0.submissions_count', 1);

        Sanctum::actingAs($manager);
        $this->postJson('/api/signup-forms/'.$form->id.'/archive')->assertOk();
        $this->getJson('/api/public/forms/'.$form->public_token)->assertNotFound();
    }

    #[Test]
    public function index_orders_by_created_at_and_paginates_when_asked(): void
    {
        $viewer = $this->employeeWithPermission(Permission::MarketingView);
        Sanctum::actingAs($viewer);

        $newest = $this->form(['name' => 'Newest']);
        $newest->forceFill(['created_at' => now()])->save();
        $older = $this->form(['name' => 'Older']);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $this->getJson('/api/signup-forms')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonMissingPath('meta');

        $this->getJson('/api/signup-forms?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function form(array $overrides = []): SignupForm
    {
        return SignupForm::factory()->create([
            'site_id' => $this->site->id,
            'subscription_list_ids' => [$this->list->id],
            'consent_text_key' => $this->text->key,
            'fields' => [
                ['key' => 'first_name', 'required' => true],
            ],
            'double_opt_in' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(SignupForm $form, array $payload, string $ip = '203.0.113.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['User-Agent' => 'SignupTest/1.0'])
            ->postJson('/api/public/forms/'.$form->public_token.'/submit', $payload);
    }

    private function fakeBrevo(): void
    {
        $sequence = 0;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function () use (&$sequence) {
                $sequence++;

                return Http::response(['messageId' => 'brevo-signup-'.$sequence], 201);
            },
        ]);
    }

    private function confirmationTokenFromMail(): string
    {
        $token = null;
        Http::assertSent(function (Request $request) use (&$token): bool {
            $html = (string) ($request->data()['htmlContent'] ?? '');
            if (preg_match('#/f/confirm/([a-f0-9]{48})#', $html, $matches) !== 1) {
                return false;
            }

            $token = $matches[1];

            return true;
        });
        $this->assertIsString($token);

        return $token;
    }
}
