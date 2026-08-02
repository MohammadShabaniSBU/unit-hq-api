<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Country;
use App\Models\Message;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\WhatsAppSender;
use App\Support\Communications\WhatsAppTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaTemplateResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private CommunicationAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $es = Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        $this->site = Site::factory()->create(['country_id' => $es->id]);
        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Whatsapp,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'project_id' => 'proj-test',
                'key_id' => 'key-id',
                'key_secret' => 'key-secret',
                'app_id' => 'app-test',
                'region' => 'us',
            ],
            'status' => CredentialStatus::Connected,
        ]);
        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);

        Http::fake([
            'us.conversation.api.sinch.com/*' => Http::response(['message_id' => '01WA-RES-0001'], 200),
        ]);
    }

    public function test_locale_ladder_any_approved_logged(): void
    {
        WhatsappTemplate::query()->create([
            'name' => 'dunning_step',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hello {{1}}',
            'variables' => [['index' => 1, 'label' => 'name', 'sample' => 'Ada']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);
        WhatsappTemplate::query()->create([
            'name' => 'dunning_step',
            'language' => 'es',
            'category' => 'utility',
            'body' => 'Hola {{1}}',
            'variables' => [['index' => 1, 'label' => 'nombre', 'sample' => 'Ada']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        // Contact locale wins.
        $esContact = Contact::factory()->create(['locale' => 'es']);
        $resolution = WhatsAppTemplateResolver::resolve(
            $this->account->id,
            'dunning_step',
            $esContact,
            $this->site,
        );
        $this->assertSame('es', $resolution['chosen']);
        $this->assertFalse($resolution['used_fallback']);

        // FR contact prefers fr, falls to any approved (es site would pick es first via site locale).
        // With only en+es approved and contact fr on ES site → site locale es (not fallback from preferred?).
        // preferred = fr (contact), site has es → picks es, used_fallback true because contactLocale !== chosen.
        $frContact = Contact::factory()->create(['locale' => 'fr']);
        ContactChannel::query()->create([
            'contact_id' => $frContact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15557654321',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $frResolution = WhatsAppTemplateResolver::resolve(
            $this->account->id,
            'dunning_step',
            $frContact,
            $this->site,
        );
        $this->assertSame('es', $frResolution['chosen']);
        $this->assertTrue($frResolution['used_fallback']);
        $this->assertSame('fr', $frResolution['preferred']);

        $result = app(WhatsAppSender::class)->sendResolvedTemplate(
            '+15557654321',
            'dunning_step',
            ['Ada'],
            $this->site,
            $frContact,
            SendContext::manual(SendClass::Transactional),
        );

        $stored = Message::query()->findOrFail($result->messageId);
        $this->assertSame('es', $stored->detail['whatsapp_template']['language'] ?? null);
        $this->assertTrue($stored->detail['whatsapp_template']['resolution']['fallback'] ?? false);
        $this->assertSame('fr', $stored->detail['whatsapp_template']['resolution']['preferred'] ?? null);
        $this->assertSame('es', $stored->detail['whatsapp_template']['resolution']['chosen'] ?? null);

        // Any-approved when preferred and site and en missing: only fr approved.
        WhatsappTemplate::query()->where('name', 'dunning_step')->delete();
        WhatsappTemplate::query()->create([
            'name' => 'dunning_step',
            'language' => 'fr',
            'category' => 'utility',
            'body' => 'Bonjour {{1}}',
            'variables' => [['index' => 1, 'label' => 'nom', 'sample' => 'Ada']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        $any = WhatsAppTemplateResolver::resolve(
            $this->account->id,
            'dunning_step',
            Contact::factory()->create(['locale' => null]),
            $this->site,
        );
        $this->assertSame('fr', $any['chosen']);
        $this->assertTrue($any['used_fallback']);
    }
}
