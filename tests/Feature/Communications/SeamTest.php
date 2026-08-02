<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\ChannelSuppression;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\WhatsAppSender;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeamTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-wa-seam';

    private Site $site;

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
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);
    }

    public function test_diffs_confined(): void
    {
        $this->assertStringNotContainsString(
            'Sinch',
            (string) file_get_contents(app_path('Support/Communications/Senders/WhatsAppSender.php')),
        );
        $this->assertStringNotContainsString(
            'Sinch',
            (string) file_get_contents(app_path('Support/Communications/DeliveryEventApplier.php')),
        );
        $this->assertStringNotContainsString(
            'Sinch',
            (string) file_get_contents(app_path('Support/Communications/InboundReceiptApplier.php')),
        );

        Http::fake([
            'us.conversation.api.sinch.com/*' => Http::response([
                'message_id' => '01WA-OUT-0001',
            ], 200),
        ]);

        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $inboundAt = Carbon::parse('2026-08-02 09:00:00', 'UTC');
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551234567',
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
            'unread_count' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-02 10:00:00', 'UTC'));

        $result = app(WhatsAppSender::class)->sendSession(
            new WhatsAppSessionMessage('+15551234567', 'Seam proof'),
            $this->site,
            $contact,
            SendContext::manual(SendClass::Transactional),
            $thread,
        );

        $this->assertSame('01WA-OUT-0001', $result->providerMessageId);
        $message = Message::query()->findOrFail($result->messageId);
        $this->assertSame(MessageStatus::Sent, $message->status);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}",
            $this->deliveryFixture('sinch_wa_delivered.json'),
        )->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Delivered, $message->status);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            $this->inboundFixture('sinch_wa_mo.json'),
        )->assertOk();

        $inbound = Message::query()
            ->where('provider_message_id', '01WA-MO-0001')
            ->firstOrFail();
        $this->assertSame(MessageStatus::Received, $inbound->status);
        $this->assertSame($contact->id, $inbound->thread?->contact_id);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            $this->inboundFixture('sinch_wa_mo_stop.json'),
        )->assertOk();

        $stop = ChannelSuppression::query()
            ->active()
            ->where('channel', Channel::Whatsapp)
            ->where('address', '+15551234567')
            ->firstOrFail();
        $this->assertSame(SuppressionScope::All, $stop->scope);
        $this->assertSame(SuppressionReason::StopKeyword, $stop->reason);

        Carbon::setTestNow();
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryFixture(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/communications/delivery/'.$name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundFixture(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/communications/inbound/'.$name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $data;
    }
}
