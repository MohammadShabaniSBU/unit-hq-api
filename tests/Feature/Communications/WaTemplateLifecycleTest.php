<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\Messages\WhatsAppTemplateMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\WhatsAppSender;
use App\Support\Communications\WhatsAppTemplateSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaTemplateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private CommunicationAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
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
            'webhook_url_token' => 'wa-tpl-webhook-token',
        ]);
        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);

        Sanctum::actingAs(Employee::factory()->manager()->create());
    }

    public function test_submit_approve_reject_revoke(): void
    {
        Http::fake([
            'provisioning.api.sinch.com/*/whatsapp/templates' => Http::response([
                'id' => 'prov-tpl-1',
                'name' => 'utility_notice',
                'language' => 'en',
                'status' => 'PENDING',
            ], 200),
            'us.conversation.api.sinch.com/*' => Http::response([
                'message_id' => '01WA-OUT-TPL',
            ], 200),
        ]);

        $create = $this->postJson('/api/whatsapp-templates', [
            'name' => 'utility_notice',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hello {{1}}',
            'variables' => [[
                'index' => 1,
                'label' => 'name',
                'token_default' => 'contact.first_name',
                'sample' => 'Alice',
            ]],
        ]);
        $create->assertCreated();
        $id = (int) $create->json('data.id');

        $submit = $this->postJson("/api/whatsapp-templates/{$id}/submit");
        $submit->assertOk();
        $this->assertSame(WhatsappTemplate::STATUS_SUBMITTED, $submit->json('data.status'));
        $this->assertSame('prov-tpl-1', $submit->json('data.provider_template_id'));

        // Approve via sync apply (poll path).
        app(WhatsAppTemplateSync::class)->apply($this->account->id, new \App\Support\Communications\Results\TemplateStatusSnapshot(
            providerTemplateId: 'prov-tpl-1',
            status: 'approved',
            name: 'utility_notice',
            language: 'en',
        ));
        $this->assertSame(WhatsappTemplate::STATUS_APPROVED, WhatsappTemplate::query()->findOrFail($id)->status);

        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551234567',
            'unread_count' => 0,
            'last_message_at' => now(),
            'last_inbound_at' => now()->subHour(),
        ]);

        $ok = app(WhatsAppSender::class)->sendTemplate(
            new WhatsAppTemplateMessage('+15551234567', 'utility_notice', 'en', ['Alice']),
            $this->site,
            $contact,
            SendContext::manual(SendClass::Transactional),
            $thread,
        );
        $this->assertSame('01WA-OUT-TPL', $ok->providerMessageId);

        // Reject path on a second language row.
        $rejected = WhatsappTemplate::query()->create([
            'name' => 'utility_notice',
            'language' => 'es',
            'category' => 'utility',
            'body' => 'Hola {{1}}',
            'variables' => [['index' => 1, 'label' => 'nombre', 'sample' => 'María']],
            'status' => WhatsappTemplate::STATUS_SUBMITTED,
            'provider_template_id' => 'prov-tpl-es',
            'communication_account_id' => $this->account->id,
            'submitted_at' => now(),
        ]);

        $rawReason = 'INVALID_FORMAT: Body uses promotional language (AB-1234)';
        app(WhatsAppTemplateSync::class)->apply($this->account->id, new \App\Support\Communications\Results\TemplateStatusSnapshot(
            providerTemplateId: 'prov-tpl-es',
            status: 'rejected',
            name: 'utility_notice',
            language: 'es',
            rejectionReason: $rawReason,
        ));
        $rejected->refresh();
        $this->assertSame(WhatsappTemplate::STATUS_REJECTED, $rejected->status);
        $this->assertSame($rawReason, $rejected->rejection_reason);

        // Instant revoke via webhook fixture.
        $this->postJson('/api/webhooks/sinch/wa-tpl-webhook-token', [
            'type' => 'whatsapp_template_status',
            'template_id' => 'prov-tpl-1',
            'name' => 'utility_notice',
            'language' => 'en',
            'status' => 'REVOKED',
        ])->assertOk();

        $this->assertSame(
            WhatsappTemplate::STATUS_REVOKED,
            WhatsappTemplate::query()->findOrFail($id)->status
        );

        // Dependent send refuses immediately after revoke.
        try {
            app(WhatsAppSender::class)->sendTemplate(
                new WhatsAppTemplateMessage('+15551234567', 'utility_notice', 'en', ['Alice']),
                $this->site,
                $contact,
                SendContext::manual(SendClass::Transactional),
                $thread,
            );
            $this->fail('Expected SendRefused after revoke');
        } catch (SendRefused $e) {
            $this->assertSame('whatsapp.template_not_approved', $e->reasonKey);
        }
    }
}
