<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\ChannelSuppression;
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
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuppressionWriterTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $emailAccount;

    private CommunicationAccount $smsAccount;

    private string $emailToken = 'tok-suppression-email';

    private string $smsToken = 'tok-suppression-sms';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();

        $this->emailAccount = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key'],
            'webhook_url_token' => $this->emailToken,
            'status' => CredentialStatus::Connected,
        ]);

        $this->smsAccount = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => [
                'account_sid' => 'ACxxx',
                'auth_token' => 'tok',
                'messaging_service_sid' => '',
            ],
            'webhook_url_token' => $this->smsToken,
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_four_reasons_scopes(): void
    {
        // Hard bounce → all / hard_bounce
        $hard = $this->seedOutboundMessage(
            '<brevo-msg-bounce@smtp-relay.mailin.fr>',
            'gone@example.com',
        );
        $this->postJson(
            "/api/webhooks/brevo/{$this->emailToken}",
            $this->deliveryFixture('brevo_hard_bounce.json'),
        )->assertOk();

        $hardRow = ChannelSuppression::query()
            ->active()
            ->where('address', 'gone@example.com')
            ->firstOrFail();
        $this->assertSame(Channel::Email, $hardRow->channel);
        $this->assertSame(SuppressionScope::All, $hardRow->scope);
        $this->assertSame(SuppressionReason::HardBounce, $hardRow->reason);
        $this->assertSame($hard->id, $hardRow->source_message_id);

        // Complaint → all / complaint
        $spam = $this->seedOutboundMessage(
            '<brevo-msg-spam@smtp-relay.mailin.fr>',
            'annoyed@example.com',
        );
        $this->postJson(
            "/api/webhooks/brevo/{$this->emailToken}",
            $this->deliveryFixture('brevo_spam.json'),
        )->assertOk();

        $spamRow = ChannelSuppression::query()
            ->active()
            ->where('address', 'annoyed@example.com')
            ->firstOrFail();
        $this->assertSame(SuppressionScope::All, $spamRow->scope);
        $this->assertSame(SuppressionReason::Complaint, $spamRow->reason);
        $this->assertSame($spam->id, $spamRow->source_message_id);

        // Soft bounce → nothing
        $beforeSoft = ChannelSuppression::query()->count();
        $this->seedOutboundMessage(
            '<brevo-msg-soft@smtp-relay.mailin.fr>',
            'busy@example.com',
        );
        $this->postJson(
            "/api/webhooks/brevo/{$this->emailToken}",
            $this->deliveryFixture('brevo_soft_bounce.json'),
        )->assertOk();
        $this->assertSame($beforeSoft, ChannelSuppression::query()->count());
        $this->assertNull(
            ChannelSuppression::query()->where('address', 'busy@example.com')->first(),
        );

        // STOP keyword → sms all / stop_keyword
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $this->postJson(
            "/api/webhooks/twilio/{$this->smsToken}/inbound",
            $this->inboundFixture('twilio_inbound_stop.json'),
        )->assertOk();

        $stopRow = ChannelSuppression::query()
            ->active()
            ->where('channel', Channel::Sms)
            ->where('address', '+15551234567')
            ->firstOrFail();
        $this->assertSame(SuppressionScope::All, $stopRow->scope);
        $this->assertSame(SuppressionReason::StopKeyword, $stopRow->reason);
        $this->assertNotNull($stopRow->source_message_id);

        // Unsubscribe (provider event) → email marketing / unsubscribed
        $unsubMsg = $this->seedOutboundMessage(
            '<brevo-msg-unsub@smtp-relay.mailin.fr>',
            'optout@example.com',
        );
        $this->postJson(
            "/api/webhooks/brevo/{$this->emailToken}",
            $this->deliveryFixture('brevo_unsubscribed.json'),
        )->assertOk();

        $unsubRow = ChannelSuppression::query()
            ->active()
            ->where('address', 'optout@example.com')
            ->firstOrFail();
        $this->assertSame(Channel::Email, $unsubRow->channel);
        $this->assertSame(SuppressionScope::Marketing, $unsubRow->scope);
        $this->assertSame(SuppressionReason::Unsubscribed, $unsubRow->reason);
        $this->assertSame($unsubMsg->id, $unsubRow->source_message_id);

        // Idempotent re-write
        SuppressionWriter::fromUnsubscribe('optout@example.com', $unsubMsg->id);
        $this->assertSame(
            1,
            ChannelSuppression::query()
                ->active()
                ->where('address', 'optout@example.com')
                ->count(),
        );

        // Address normalization on write
        SuppressionWriter::write(
            Channel::Email,
            '  Name <Typo@Example.COM>  ',
            SuppressionScope::All,
            SuppressionReason::Manual,
        );
        $this->assertNotNull(
            ChannelSuppression::query()
                ->active()
                ->where('address', 'typo@example.com')
                ->first(),
        );
    }

    private function seedOutboundMessage(string $providerMessageId, string $to): Message
    {
        $contact = Contact::factory()->create();

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Hello',
            'channel_key' => null,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
            'body_text' => 'Hi',
            'body_html' => '<p>Hi</p>',
            'from_address' => 'desk@example.com',
            'to_address' => $to,
            'provider' => Provider::Brevo,
            'communication_account_id' => $this->emailAccount->id,
            'provider_message_id' => $providerMessageId,
            'source' => MessageSource::Manual,
            'source_ref' => null,
            'delivery_events' => [],
            'sent_at' => now(),
        ]);

        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'occurred_at' => now(),
            'summary' => 'Hi',
            'content' => 'Hi',
            'provider_message_id' => $providerMessageId,
            'communication_account_id' => $this->emailAccount->id,
            'message_id' => $message->id,
        ]);

        return $message;
    }

    /** @return array<string, mixed> */
    private function deliveryFixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/delivery/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @return array<string, mixed> */
    private function inboundFixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/inbound/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
