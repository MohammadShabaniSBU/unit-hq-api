<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\PlaybookKind;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OfferDelivery;
use App\Models\Playbook;
use App\Models\Site;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class MessageStoreTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    public function test_three_sources_one_shape(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::sequence()
                ->push(['messageId' => 'brevo-manual'], 201)
                ->push(['messageId' => 'brevo-offer'], 201)
                ->push(['messageId' => 'brevo-playbook'], 201),
            'api.twilio.com/*' => Http::response(['sid' => 'SM-manual'], 201),
        ]);

        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $this->seedSmsAccount($site);
        $contact = Contact::factory()->create();

        $manual = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('renter@example.com')],
                subject: 'Hello',
                html: '<p>Hi</p>',
                text: 'Hi',
            ),
            $site,
            $contact,
            SendContext::manual(),
        );

        $offer = Offer::factory()->sent()->create(['contact_id' => $contact->id]);
        $delivery = OfferDelivery::query()->create([
            'offer_id' => $offer->id,
            'channel' => 'email',
            'recipient_address' => 'renter@example.com',
            'sent_at' => now(),
            'delivery_status' => 'queued',
        ]);

        $offerResult = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('renter@example.com')],
                subject: 'Your offer',
                html: '<p>Offer</p>',
                text: 'Offer',
            ),
            $site,
            $contact,
            SendContext::offer($delivery),
        );

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Chase',
            'is_active' => true,
            'enrolment_filters' => [],
        ]);
        $automation = Automation::query()->create([
            'name' => 'Chase compiled',
            'status' => AutomationStatus::Active,
            'version' => 1,
            'playbook_id' => $playbook->id,
        ]);
        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
            'status' => AutomationRunStatus::Running,
            'depth' => 0,
        ]);
        $step = AutomationRunStep::query()->create([
            'run_id' => $run->id,
            'node_id' => null,
            'node_type' => 'action.send_email',
            'status' => 'succeeded',
        ]);

        $playbookResult = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('renter@example.com')],
                subject: 'Following up',
                html: '<p>Bump</p>',
                text: 'Bump',
            ),
            $site,
            $contact,
            SendContext::playbook($run, $step),
        );

        $sms = app(SmsSender::class)->send(
            new SmsMessage('+15559998888', 'Ping'),
            $site,
            $contact,
            SendContext::manual(),
        );

        foreach ([$manual, $offerResult, $playbookResult, $sms] as $result) {
            $this->assertNotNull($result->messageId);
            $this->assertNotNull($result->interactionId);

            $message = Message::query()->findOrFail($result->messageId);
            $interaction = Interaction::query()->findOrFail($result->interactionId);

            $this->assertSame(MessageStatus::Sent, $message->status);
            $this->assertSame($message->id, $interaction->message_id);
            $this->assertNotNull($message->body_text);
            $this->assertNotNull($message->from_address);
            $this->assertNotNull($message->to_address);
            $this->assertNotNull($message->threading_evidence);
        }

        $this->assertSame(MessageSource::Manual, Message::query()->findOrFail($manual->messageId)->source);
        $this->assertSame(MessageSource::Offer, Message::query()->findOrFail($offerResult->messageId)->source);
        $this->assertSame(MessageSource::Playbook, Message::query()->findOrFail($playbookResult->messageId)->source);

        $delivery->refresh();
        $this->assertSame($offerResult->messageId, $delivery->message_id);
        $this->assertSame('brevo-offer', $delivery->provider_message_id);
        $this->assertSame('sent', $delivery->delivery_status);
    }

    public function test_rejection_recorded_failed(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['message' => 'rejected'], 400),
        ]);

        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create();

        try {
            app(EmailSender::class)->send(
                new EmailMessage(
                    to: [new EmailAddress('renter@example.com')],
                    subject: 'Nope',
                    html: '<p>x</p><script>alert(1)</script>',
                    text: 'x',
                ),
                $site,
                $contact,
                SendContext::manual(),
            );
            $this->fail('Expected ProviderRequestFailed');
        } catch (ProviderRequestFailed) {
            // expected
        }

        $message = Message::query()->first();
        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertNull($message->provider_message_id);
        $this->assertNull($message->sent_at);
        $this->assertStringNotContainsString('<script', (string) $message->body_html);

        $interaction = Interaction::query()->first();
        $this->assertNotNull($interaction);
        $this->assertSame($message->id, $interaction->message_id);
    }

    public function test_interaction_and_delivery_linkage(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-link'], 201),
        ]);

        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create();
        $offer = Offer::factory()->sent()->create(['contact_id' => $contact->id]);
        $delivery = OfferDelivery::query()->create([
            'offer_id' => $offer->id,
            'channel' => 'email',
            'recipient_address' => 'renter@example.com',
            'sent_at' => now(),
            'delivery_status' => 'queued',
        ]);

        $result = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('renter@example.com')],
                subject: 'Linked',
                html: '<p>Body</p>',
                text: 'Body',
            ),
            $site,
            $contact,
            SendContext::offer($delivery),
        );

        $interaction = Interaction::query()->findOrFail($result->interactionId);
        $delivery->refresh();

        $this->assertSame($result->messageId, $interaction->message_id);
        $this->assertSame($result->messageId, $delivery->message_id);
        $this->assertSame(
            $interaction->message_id,
            $delivery->message_id,
        );
    }
}
