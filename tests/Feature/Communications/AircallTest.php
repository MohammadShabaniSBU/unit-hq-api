<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AircallTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-aircall-test';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();

        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Call,
            'provider' => Provider::Aircall,
            'is_active' => true,
            'credentials' => [
                'api_id' => 'aircall-id',
                'api_token' => 'aircall-token',
            ],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_call_lifecycle_to_messages(): void
    {
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        foreach (['aircall_call_created.json', 'aircall_call_answered.json', 'aircall_call_ended.json'] as $fixture) {
            $this->postJson(
                "/api/webhooks/aircall/{$this->webhookToken}",
                $this->inboundFixture($fixture),
            )->assertOk();
        }

        $messages = Message::query()
            ->where('provider', Provider::Aircall)
            ->where('provider_message_id', '812001')
            ->get();
        $this->assertCount(1, $messages);

        $message = $messages->firstOrFail();
        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertStringContainsString('answered', (string) $message->body_text);
        $this->assertStringContainsString('Jane Agent', (string) $message->body_text);
        $this->assertSame(
            'https://assets.aircall.io/calls/812001/recording.mp3',
            $message->source_ref['recording_url'] ?? null,
        );

        $thread = MessageThread::query()->findOrFail($message->message_thread_id);
        $this->assertSame(Channel::Call, $thread->channel);
        $this->assertSame('+15551234567', $thread->channel_key);
        // Answered call must not bump unread.
        $this->assertSame(0, $thread->unread_count);

        $interaction = Interaction::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame('call', $interaction->channel);
        $this->assertSame('Jane Agent', $interaction->metadata['agent'] ?? null);

        // Missed inbound → unread.
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_missed.json'),
        )->assertOk();

        $missed = Message::query()
            ->where('provider_message_id', '812002')
            ->firstOrFail();
        $missedThread = MessageThread::query()->findOrFail($missed->message_thread_id);
        $this->assertSame(1, $missedThread->unread_count);
        $this->assertStringContainsString('missed', (string) $missed->body_text);

        // Voicemail also counts as unread on its own call.
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_voicemail_left.json'),
        )->assertOk();

        $voicemail = Message::query()
            ->where('provider_message_id', '812003')
            ->firstOrFail();
        $this->assertSame(
            'https://assets.aircall.io/calls/812003/voicemail.mp3',
            $voicemail->source_ref['voicemail_url'] ?? null,
        );
        $vmThread = MessageThread::query()->findOrFail($voicemail->message_thread_id);
        $this->assertSame(2, $vmThread->unread_count);
    }

    public function test_unmatched_number_triage(): void
    {
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_missed.json'),
        )->assertOk();

        $this->assertSame(0, Message::query()->count());
        $triage = CommsTriage::query()->firstOrFail();
        $this->assertSame(Provider::Aircall, $triage->provider);
        $this->assertSame(Channel::Call, $triage->channel);
        $this->assertSame('812002', $triage->provider_message_id);
        $this->assertSame('+15551234567', $triage->sender_value);
        $this->assertSame('pending', $triage->status);
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
