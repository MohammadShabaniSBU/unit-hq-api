<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\CredentialStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\LegalEntity;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\AccountPurpose;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\LegacyEmailBlocksHtml;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\MessageSource;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Marketing\MarketingFooter;
use App\Support\Marketing\PreferenceToken;
use Database\Factories\TemplateFamilyFactory;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class MarketingSendLaneTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function account(
        AccountPurpose $purpose,
        Provider $provider = Provider::Brevo,
        ?Site $site = null,
        bool $active = true,
        array $credentials = ['api_key' => 'k'],
    ): CommunicationAccount {
        return CommunicationAccount::query()->create([
            'scope' => $site === null ? AccountScope::Company : AccountScope::Site,
            'site_id' => $site?->id,
            'channel' => Channel::Email,
            'provider' => $provider,
            'purpose' => $purpose,
            'is_active' => $active,
            'credentials' => $credentials,
            'status' => CredentialStatus::Connected,
        ]);
    }

    #[Test]
    public function existing_accounts_default_to_the_all_purpose(): void
    {
        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'k'],
            'status' => CredentialStatus::Connected,
        ]);

        $this->assertSame(AccountPurpose::All, $account->fresh()->purpose);
    }

    #[Test]
    public function marketing_prefers_the_marketing_account_and_falls_back_to_all(): void
    {
        $all = $this->account(AccountPurpose::All);
        $resolver = app(ProviderResolver::class);

        $this->assertSame(
            $all->id,
            $resolver->resolve(Channel::Email, null, SendClass::Marketing)->account->id,
        );

        $marketing = $this->account(AccountPurpose::Marketing, Provider::Postmark, credentials: ['server_token' => 't']);

        $this->assertSame(
            $marketing->id,
            $resolver->resolve(Channel::Email, null, SendClass::Marketing)->account->id,
        );
    }

    #[Test]
    public function marketing_never_falls_back_to_a_transactional_account(): void
    {
        $this->account(AccountPurpose::Transactional);

        $this->expectException(ChannelNotConfigured::class);
        $this->expectExceptionMessage('marketing');

        app(ProviderResolver::class)->resolve(Channel::Email, null, SendClass::Marketing);
    }

    #[Test]
    public function transactional_prefers_transactional_then_all_and_never_marketing(): void
    {
        $resolver = app(ProviderResolver::class);

        $marketing = $this->account(AccountPurpose::Marketing, Provider::Postmark, credentials: ['server_token' => 't']);

        try {
            $resolver->resolve(Channel::Email);
            $this->fail('A marketing-only channel must not serve transactional sends.');
        } catch (ChannelNotConfigured) {
            $this->addToAssertionCount(1);
        }

        $all = $this->account(AccountPurpose::All);
        $this->assertSame($all->id, $resolver->resolve(Channel::Email)->account->id);
        $this->assertNotSame($marketing->id, $resolver->resolve(Channel::Email)->account->id);

        $transactional = $this->account(AccountPurpose::Transactional, Provider::Postmark, credentials: ['server_token' => 'x']);
        $this->assertSame($transactional->id, $resolver->resolve(Channel::Email)->account->id);
    }

    #[Test]
    public function a_site_account_wins_within_a_purpose(): void
    {
        $site = Site::factory()->create();
        $this->account(AccountPurpose::Marketing, Provider::Postmark, credentials: ['server_token' => 't']);
        $siteAccount = $this->account(AccountPurpose::Marketing, Provider::Brevo, $site);

        $this->assertSame(
            $siteAccount->id,
            app(ProviderResolver::class)->resolve(Channel::Email, $site, SendClass::Marketing)->account->id,
        );
    }

    #[Test]
    public function a_marketing_send_with_only_a_transactional_account_is_refused(): void
    {
        $site = Site::factory()->create();
        $this->account(AccountPurpose::Transactional);
        $contact = Contact::factory()->create();
        Http::fake();

        try {
            app(EmailSender::class)->send(
                new EmailMessage(
                    to: [new EmailAddress('ada@example.com')],
                    subject: 'Offer',
                    html: '<p>Hi</p>',
                    text: 'Hi',
                ),
                $site,
                $contact,
                SendContext::manual(SendClass::Marketing),
            );
            $this->fail('Marketing must not use a transactional account.');
        } catch (ChannelNotConfigured) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_marketing_send_uses_the_marketing_account(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $this->account(AccountPurpose::Marketing, Provider::Postmark, credentials: ['server_token' => 'mkt-token']);
        $contact = Contact::factory()->create();
        Http::fake([
            'api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-1', 'ErrorCode' => 0], 200),
        ]);

        $result = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('ada@example.com')],
                subject: 'Offer',
                html: '<p>Hi</p>',
                text: 'Hi',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing),
        );

        $this->assertSame(Provider::Postmark, $result->provider);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'postmarkapp.com'));
    }

    #[Test]
    public function the_campaign_context_carries_provenance_and_the_marketing_class(): void
    {
        $context = SendContext::campaign();

        $this->assertSame(MessageSource::Campaign, $context->source);
        $this->assertSame(SendClass::Marketing, $context->class);
        $this->assertArrayHasKey('campaign_id', $context->sourceRef ?? []);
        $this->assertArrayHasKey('campaign_send_id', $context->sourceRef ?? []);
        $this->assertArrayHasKey('campaign_recipient_id', $context->sourceRef ?? []);
    }

    /** @return array{0: Site, 1: Contact, 2: SubscriptionList} */
    private function footerFixture(): array
    {
        $this->seed(SubscriptionListSeeder::class);
        $list = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();

        $entity = LegalEntity::factory()->create([
            'legal_name' => 'Trastero Madrid S.L.',
            'trading_name' => 'Keevaris Madrid',
            'tax_id' => 'B12345674',
            'address_line1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postal_code' => '28013',
        ]);
        $site = Site::factory()->create(['legal_entity_id' => $entity->id]);
        $contact = Contact::factory()->create(['email' => 'ada@example.com', 'locale' => 'es']);

        return [$site, $contact, $list];
    }

    #[Test]
    public function the_email_footer_carries_identity_list_label_and_working_links(): void
    {
        [$site, $contact, $list] = $this->footerFixture();

        $footer = MarketingFooter::render($site, $contact, Channel::Email, $list->id);

        foreach (['Trastero Madrid S.L.', 'B12345674', 'Calle Mayor 1', '28013 Madrid'] as $part) {
            $this->assertStringContainsString($part, $footer['html']);
            $this->assertStringContainsString($part, $footer['text']);
        }
        $this->assertStringContainsString((string) $list->labels['es'], $footer['text']);
        $this->assertStringContainsString('¿Por qué recibo esto?', $footer['text']);

        $this->assertSame(1, preg_match('#/preferences/([A-Za-z0-9_-]+)#', $footer['text'], $match));
        $claims = PreferenceToken::parse($match[1]);
        $this->assertNotNull($claims);
        $this->assertSame($contact->id, $claims->contactId);
        $this->assertSame($list->id, $claims->listId);
        $this->assertStringContainsString('/api/comms/unsubscribe/'.$match[1], $footer['text']);
    }

    #[Test]
    public function a_marketing_render_appends_the_footer_and_a_transactional_render_does_not(): void
    {
        [$site, $contact, $list] = $this->footerFixture();

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Promo',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Promo',
            'legacy_html' => LegacyEmailBlocksHtml::fromBlocks([[
                'type' => 'text',
                'props' => ['content' => 'Big offer', 'align' => 'left', 'fontSize' => 16, 'color' => '#000000'],
            ]]),
        ]]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();
        $context = new RunContext(subjectBag: SubjectTokenBag::forContact($contact));

        $marketing = EmailTemplateRenderer::render(
            $variant,
            $context,
            class: SendClass::Marketing,
            site: $site,
            contact: $contact,
            listId: $list->id,
        );
        $transactional = EmailTemplateRenderer::render($variant, $context);

        $this->assertStringContainsString('Big offer', $marketing['html']);
        $this->assertStringContainsString('Trastero Madrid S.L.', $marketing['html']);
        $this->assertStringContainsString('/preferences/', $marketing['html']);
        $this->assertStringContainsString('/api/comms/unsubscribe/', $marketing['html']);
        $this->assertStringContainsString('Trastero Madrid S.L.', $marketing['text']);

        $this->assertStringNotContainsString('Trastero Madrid S.L.', $transactional['html']);
        $this->assertStringNotContainsString('/preferences/', $transactional['html']);
        $this->assertStringNotContainsString('data-marketing-footer', $transactional['html']);
    }

    #[Test]
    public function a_marketing_render_cannot_skip_the_footer(): void
    {
        $this->seed(SubscriptionListSeeder::class);
        $contact = Contact::factory()->create();
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Promo',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Promo',
            'legacy_html' => '<p>Hi</p>',
        ]]);

        $this->expectException(\LogicException::class);

        EmailTemplateRenderer::render(
            $family->currentVersion()->firstOrFail()->variants()->firstOrFail(),
            new RunContext(subjectBag: SubjectTokenBag::forContact($contact)),
            class: SendClass::Marketing,
        );
    }

    #[Test]
    public function the_sms_footer_is_one_locale_aware_line_with_the_preferences_link(): void
    {
        [$site, $contact] = $this->footerFixture();

        $footer = MarketingFooter::render($site, $contact, Channel::Sms, null, '+34600111222');

        $this->assertSame('', $footer['html']);
        $this->assertStringStartsWith('Keevaris Madrid: baja ', $footer['text']);
        $this->assertStringContainsString('/preferences/', $footer['text']);
        $this->assertStringNotContainsString("\n", $footer['text']);

        $contact->forceFill(['locale' => 'fr'])->save();
        $french = MarketingFooter::render($site, $contact->fresh(), Channel::Sms, null, '+34600111222');
        $this->assertStringContainsString('désinscription', $french['text']);
    }

    #[Test]
    public function marketing_settings_default_and_round_trip(): void
    {
        $employee = $this->employeeWithPermission(Permission::SettingsManage);
        Sanctum::actingAs($employee);

        $this->getJson('/api/settings/marketing')
            ->assertOk()
            ->assertJsonPath('data.marketing_frequency_cap_count', 2)
            ->assertJsonPath('data.marketing_frequency_cap_days', 7)
            ->assertJsonPath('data.marketing_exclude_delinquent', true)
            ->assertJsonPath('data.marketing_exclude_active_playbook_kinds', ['debt_process'])
            ->assertJsonPath('data.marketing_approval_threshold', 500);

        $this->patchJson('/api/settings/marketing', [
            'marketing_frequency_cap_count' => 3,
            'marketing_exclude_delinquent' => false,
            'marketing_exclude_active_playbook_kinds' => ['debt_process', 'lead_chase'],
        ])
            ->assertOk()
            ->assertJsonPath('data.marketing_frequency_cap_count', 3)
            ->assertJsonPath('data.marketing_frequency_cap_days', 7)
            ->assertJsonPath('data.marketing_exclude_delinquent', false)
            ->assertJsonPath('data.marketing_exclude_active_playbook_kinds', ['debt_process', 'lead_chase']);

        $this->assertSame(3, Setting::marketing()->frequencyCapCount);

        $this->patchJson('/api/settings/marketing', [
            'marketing_exclude_active_playbook_kinds' => ['nope'],
        ])->assertUnprocessable();

        $this->patchJson('/api/settings/marketing', [
            'marketing_frequency_cap_days' => 0,
        ])->assertUnprocessable();
    }

    #[Test]
    public function marketing_settings_need_the_settings_permission(): void
    {
        Sanctum::actingAs($this->employeeWithoutPermissions());

        $this->getJson('/api/settings/marketing')->assertForbidden();
        $this->patchJson('/api/settings/marketing', ['marketing_approval_threshold' => 10])->assertForbidden();
    }

    #[Test]
    public function the_communications_api_keeps_one_account_per_purpose(): void
    {
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));
        Http::fake([
            'api.postmarkapp.com/*' => Http::response(['ErrorCode' => 0, 'Name' => 'Server'], 200),
            'api.brevo.com/*' => Http::response(['email' => 'x@y.z'], 200),
        ]);

        $this->putJson('/api/settings/communications/email', [
            'provider' => 'brevo',
            'credentials' => ['api_key' => 'k'],
        ])->assertOk();

        $this->putJson('/api/settings/communications/email', [
            'provider' => 'postmark',
            'purpose' => 'marketing',
            'credentials' => ['server_token' => 't'],
        ])->assertOk();

        $this->assertSame(2, CommunicationAccount::query()->where('is_active', true)->count());
        $this->assertSame(
            AccountPurpose::All,
            CommunicationAccount::query()->where('provider', Provider::Brevo)->firstOrFail()->purpose,
        );
        $this->assertSame(
            AccountPurpose::Marketing,
            CommunicationAccount::query()->where('provider', Provider::Postmark)->firstOrFail()->purpose,
        );

        $this->putJson('/api/settings/communications/email', [
            'provider' => 'postmark',
            'purpose' => 'bogus',
            'credentials' => ['server_token' => 't'],
        ])->assertUnprocessable();

        $this->getJson('/api/settings/communications')
            ->assertOk()
            ->assertJsonFragment(['purpose' => 'marketing'])
            ->assertJsonFragment(['purpose' => 'all']);

        $this->deleteJson('/api/settings/communications/email/postmark?purpose=marketing')->assertNoContent();
        $this->assertSame(1, CommunicationAccount::query()->count());
    }
}
