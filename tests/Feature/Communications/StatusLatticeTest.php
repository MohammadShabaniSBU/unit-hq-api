<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusLatticeTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-wa-lattice';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();

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
    }

    public function test_read_rank(): void
    {
        $this->assertSame(30, DeliveryStatus::Delivered->rank());
        $this->assertSame(40, DeliveryStatus::Read->rank());
        $this->assertTrue(
            (int) DeliveryStatus::Read->rank() > (int) DeliveryStatus::Delivered->rank(),
        );
        $this->assertSame(MessageStatus::Read, DeliveryStatus::Read->toMessageStatus());

        $contact = Contact::factory()->create();
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551234567',
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
            'body_text' => 'Hello',
            'from_address' => '+15550009999',
            'to_address' => '+15551234567',
            'provider' => Provider::Sinch,
            'provider_message_id' => '01WA-OUT-0001',
            'communication_account_id' => $this->account->id,
            'source' => MessageSource::Manual,
            'sent_at' => now(),
        ]);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}",
            $this->fixture('sinch_wa_delivered.json'),
        )->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Delivered, $message->status);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}",
            $this->fixture('sinch_wa_read.json'),
        )->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Read, $message->status);

        // Forward-only: delivered after read must not regress.
        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}",
            [
                'message_id' => '01WA-OUT-0001',
                'status' => 'DELIVERED',
                'recipient' => '+15551234567',
                'at' => '2026-08-02T10:42:00.000Z',
            ],
        )->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Read, $message->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
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
}
