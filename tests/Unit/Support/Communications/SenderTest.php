<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Provider;
use App\Models\Message;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_sender_fills_identity_stamps_account_and_writes_interaction(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-1'], 201),
        ]);

        $site = Site::factory()->create();
        $contact = Contact::factory()->create();

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'key'],
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Email,
            'account_id' => $account->id,
            'from_name' => 'Site Desk',
            'from_email' => 'desk@site.test',
            'reply_to_email' => 'reply@site.test',
        ]);

        $result = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('renter@example.com')],
                subject: 'Offer',
                html: '<p>Offer</p>',
                text: 'Offer',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $this->assertSame($account->id, $result->accountId);
        $this->assertSame('brevo-1', $result->providerMessageId);
        $this->assertNotNull($result->messageId);
        $this->assertNotNull($result->interactionId);

        Http::assertSent(function ($request) use ($site) {
            $data = $request->data();

            return ($data['sender']['email'] ?? null) === 'desk@site.test'
                && ($data['replyTo']['email'] ?? null) === 'reply@site.test'
                && in_array('site:'.$site->id, $data['tags'] ?? [], true);
        });

        $interaction = Interaction::query()->first();
        $this->assertNotNull($interaction);
        $this->assertSame('brevo-1', $interaction->provider_message_id);
        $this->assertSame($account->id, $interaction->communication_account_id);
        $this->assertSame($contact->id, $interaction->contact_id);
        $this->assertSame($result->messageId, $interaction->message_id);

        $message = Message::query()->find($result->messageId);
        $this->assertNotNull($message);
        $this->assertSame('sent', $message->status->value);
        $this->assertSame('manual', $message->source->value);
    }

    public function test_sms_sender_fills_from_number(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);

        $site = Site::factory()->create();

        $account = CommunicationAccount::query()->create([
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
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Sms,
            'from_number' => '+15550001111',
        ]);

        $result = app(SmsSender::class)->send(
            new SmsMessage('+15559999999', 'Hello'),
            $site,
            null,
            SendContext::manual(SendClass::Transactional),
        );

        $this->assertSame($account->id, $result->accountId);
        $this->assertSame(0, Interaction::query()->count());

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['From'] ?? null) === '+15550001111';
        });
    }
}
