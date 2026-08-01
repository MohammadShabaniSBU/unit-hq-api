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
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundThreadingTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $postmark;

    private CommunicationAccount $twilio;

    private string $postmarkToken = 'tok-inbound-postmark';

    private string $twilioToken = 'tok-inbound-twilio';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();

        $this->postmark = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'test-token'],
            'webhook_url_token' => $this->postmarkToken,
            'status' => CredentialStatus::Connected,
        ]);

        $this->twilio = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => [
                'account_sid' => 'ACxxx',
                'auth_token' => 'tok',
            ],
            'webhook_url_token' => $this->twilioToken,
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_references_subject_new_priority(): void
    {
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Email,
            'value' => 'renter@example.com',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $originThread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Welcome to Unit HQ',
            'channel_key' => null,
            'last_message_at' => now()->subDay(),
            'unread_count' => 0,
        ]);

        Message::query()->create([
            'message_thread_id' => $originThread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
            'body_text' => 'Playbook email',
            'body_html' => null,
            'from_address' => 'desk@example.com',
            'to_address' => 'renter@example.com',
            'provider' => Provider::Postmark,
            'communication_account_id' => $this->postmark->id,
            'provider_message_id' => 'outbound-playbook-msg-001@unit-hq.test',
            'source' => MessageSource::Playbook,
            'sent_at' => now()->subDay(),
        ]);

        // (1) References match → originating thread
        $this->postJson(
            "/api/webhooks/postmark/{$this->postmarkToken}/inbound",
            $this->inboundFixture('postmark_inbound_multipart.json'),
        )->assertOk();

        $reply = Message::query()
            ->where('provider_message_id', '00000000-0000-0000-0000-00000000in01')
            ->firstOrFail();
        $this->assertSame($originThread->id, $reply->message_thread_id);
        $this->assertSame('references', $reply->threading_evidence['strategy'] ?? null);

        // (2) Subject match (no references) → most recent subject thread
        $subjectPayload = $this->inboundFixture('postmark_inbound_multipart.json');
        $subjectPayload['MessageID'] = '00000000-0000-0000-0000-00000000in02';
        $subjectPayload['Headers'] = [
            ['Name' => 'Message-ID', 'Value' => '<00000000-0000-0000-0000-00000000in02@mtasv.net>'],
        ];
        $subjectPayload['Subject'] = 'Re: Welcome to Unit HQ';

        $this->postJson(
            "/api/webhooks/postmark/{$this->postmarkToken}/inbound",
            $subjectPayload,
        )->assertOk();

        $bySubject = Message::query()
            ->where('provider_message_id', '00000000-0000-0000-0000-00000000in02')
            ->firstOrFail();
        $this->assertSame($originThread->id, $bySubject->message_thread_id);
        $this->assertSame('subject', $bySubject->threading_evidence['strategy'] ?? null);

        // (3) New subject → new thread
        $newPayload = $subjectPayload;
        $newPayload['MessageID'] = '00000000-0000-0000-0000-00000000in03';
        $newPayload['Subject'] = 'Brand new topic';
        $newPayload['Headers'] = [
            ['Name' => 'Message-ID', 'Value' => '<00000000-0000-0000-0000-00000000in03@mtasv.net>'],
        ];

        $this->postJson(
            "/api/webhooks/postmark/{$this->postmarkToken}/inbound",
            $newPayload,
        )->assertOk();

        $fresh = Message::query()
            ->where('provider_message_id', '00000000-0000-0000-0000-00000000in03')
            ->firstOrFail();
        $this->assertNotSame($originThread->id, $fresh->message_thread_id);
        $this->assertSame('new', $fresh->threading_evidence['strategy'] ?? null);

        // SMS → (contact, number) thread
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $smsThread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Sms,
            'subject' => null,
            'channel_key' => '+15551234567',
            'last_message_at' => now()->subHours(2),
            'unread_count' => 0,
        ]);

        $this->postJson(
            "/api/webhooks/twilio/{$this->twilioToken}",
            $this->inboundFixture('twilio_inbound_sms.json'),
        )->assertOk();

        $sms = Message::query()
            ->where('provider_message_id', 'SMinbound000000000000000000000001')
            ->firstOrFail();
        $this->assertSame($smsThread->id, $sms->message_thread_id);
        $this->assertSame('channel_key', $sms->threading_evidence['strategy'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundFixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/inbound/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
