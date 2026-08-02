<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\Messages\WhatsAppTemplateMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaSendTest extends TestCase
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
        ]);
        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);

        $seq = 0;
        Http::fake([
            'us.conversation.api.sinch.com/*' => function () use (&$seq) {
                $seq++;

                return Http::response([
                    'message_id' => '01WA-OUT-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                ], 200);
            },
        ]);
    }

    public function test_session_vs_template_matrix(): void
    {
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $inboundAt = Carbon::parse('2026-08-01 12:00:00', 'UTC');
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551234567',
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
            'unread_count' => 0,
        ]);

        // Session inside window → delivers + threads.
        Carbon::setTestNow(Carbon::parse('2026-08-02 11:59:59', 'UTC'));
        $sessionOk = app(WhatsAppSender::class)->sendSession(
            new WhatsAppSessionMessage('+15551234567', 'Inside window'),
            $this->site,
            $contact,
            SendContext::manual(SendClass::Transactional),
            $thread,
        );
        $this->assertSame('01WA-OUT-0001', $sessionOk->providerMessageId);
        $this->assertSame(MessageStatus::Sent, Message::query()->findOrFail($sessionOk->messageId)->status);
        $this->assertSame(
            $thread->id,
            Message::query()->findOrFail($sessionOk->messageId)->message_thread_id,
        );

        // Session outside window → refuse locally.
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:01', 'UTC'));
        try {
            app(WhatsAppSender::class)->sendSession(
                new WhatsAppSessionMessage('+15551234567', 'Outside window'),
                $this->site,
                $contact,
                SendContext::manual(SendClass::Transactional),
                $thread,
            );
            $this->fail('Expected SendRefused for closed window');
        } catch (SendRefused $e) {
            $this->assertSame('whatsapp.window_closed', $e->reasonKey);
        }

        // Template bypasses window; refuses non-approved.
        try {
            app(WhatsAppSender::class)->sendTemplate(
                new WhatsAppTemplateMessage('+15551234567', 'utility_notice', 'en', ['Alice']),
                $this->site,
                $contact,
                SendContext::manual(SendClass::Transactional),
                $thread,
            );
            $this->fail('Expected SendRefused for non-approved template');
        } catch (SendRefused $e) {
            $this->assertSame('whatsapp.template_not_approved', $e->reasonKey);
        }

        WhatsappTemplate::query()->create([
            'name' => 'utility_notice',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hello {{1}}',
            'variables' => [['index' => 1, 'label' => 'name', 'sample' => 'Alice']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        $templateOk = app(WhatsAppSender::class)->sendTemplate(
            new WhatsAppTemplateMessage('+15551234567', 'utility_notice', 'en', ['Alice']),
            $this->site,
            $contact,
            SendContext::manual(SendClass::Transactional),
            $thread,
        );
        $this->assertSame('01WA-OUT-0002', $templateOk->providerMessageId);
        $stored = Message::query()->findOrFail($templateOk->messageId);
        $this->assertSame('Hello Alice', $stored->body_text);
        $this->assertSame('utility_notice', $stored->detail['whatsapp_template']['name'] ?? null);

        // Phone without whatsapp channel → consent floor.
        $phoneOnly = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $phoneOnly->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15559876543',
            'is_primary' => true,
            'opted_in' => true,
        ]);
        try {
            app(WhatsAppSender::class)->sendTemplate(
                new WhatsAppTemplateMessage('+15559876543', 'utility_notice', 'en', ['Bob']),
                $this->site,
                $phoneOnly,
                SendContext::manual(SendClass::Transactional),
            );
            $this->fail('Expected SendRefused for consent floor');
        } catch (SendRefused $e) {
            $this->assertSame('whatsapp.consent_floor', $e->reasonKey);
        }

        Carbon::setTestNow();
    }
}
