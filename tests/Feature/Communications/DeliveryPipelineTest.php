<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\AutomationStatus;
use App\Enums\CredentialStatus;
use App\Enums\PlaybookKind;
use App\Events\ChannelDeliveryFailed;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\CommsWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Models\OfferDelivery;
use App\Models\Playbook;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryPipelineTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-delivery-pipeline';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();

        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key'],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_idempotent_including_derived_keys(): void
    {
        $message = $this->seedOutboundMessage(
            providerMessageId: '<brevo-msg-soft@smtp-relay.mailin.fr>',
            status: MessageStatus::Sent,
        );

        $payload = $this->fixture('brevo_soft_bounce.json');

        $this->postJson("/api/webhooks/brevo/{$this->webhookToken}", $payload)
            ->assertOk()
            ->assertJson(['message' => 'ok']);

        $this->postJson("/api/webhooks/brevo/{$this->webhookToken}", $payload)
            ->assertOk();

        $this->assertSame(1, CommsWebhookEvent::query()->count());
        $row = CommsWebhookEvent::query()->firstOrFail();
        $this->assertSame('processed', $row->processing_status);
        $this->assertSame($message->id, $row->message_id);

        $message->refresh();
        $this->assertSame(MessageStatus::Bounced, $message->status);
        $this->assertCount(1, $message->delivery_events ?? []);
    }

    public function test_forward_only_lattice(): void
    {
        $message = $this->seedOutboundMessage(
            providerMessageId: '<brevo-msg-delivered@smtp-relay.mailin.fr>',
            status: MessageStatus::Sent,
        );
        $interaction = $message->interaction;
        $this->assertNotNull($interaction);
        $touchedAt = $interaction->updated_at?->copy();

        // Delivered first.
        $this->postJson(
            "/api/webhooks/brevo/{$this->webhookToken}",
            $this->fixture('brevo_delivered.json'),
        )->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Delivered, $message->status);

        // Out-of-order "sent" must not regress.
        $this->postJson("/api/webhooks/brevo/{$this->webhookToken}", [
            'id' => 999001,
            'event' => 'sent',
            'email' => 'renter@example.com',
            'date' => '2026-08-02T10:14:00+00:00',
            'message-id' => '<brevo-msg-delivered@smtp-relay.mailin.fr>',
        ])->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Delivered, $message->status);

        // Bounce wins over delivered; subsequent open must not regress.
        $this->postJson("/api/webhooks/brevo/{$this->webhookToken}", [
            'id' => 999002,
            'event' => 'hardBounce',
            'email' => 'renter@example.com',
            'date' => '2026-08-02T10:18:00+00:00',
            'message-id' => '<brevo-msg-delivered@smtp-relay.mailin.fr>',
            'reason' => 'mailbox_not_found',
        ])->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Bounced, $message->status);

        $this->postJson("/api/webhooks/brevo/{$this->webhookToken}", [
            'id' => 999003,
            'event' => 'opened',
            'email' => 'renter@example.com',
            'date' => '2026-08-02T10:19:00+00:00',
            'message-id' => '<brevo-msg-delivered@smtp-relay.mailin.fr>',
        ])->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Bounced, $message->status);
        $this->assertCount(4, $message->delivery_events ?? []);

        $interaction->refresh();
        $this->assertTrue(
            $interaction->updated_at !== null
            && ($touchedAt === null || $interaction->updated_at->gte($touchedAt)),
        );
    }

    public function test_playbook_step_backfill(): void
    {
        $contact = Contact::factory()->create();
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
            'status' => AutomationRunStepStatus::Succeeded,
            'output' => [
                'to' => 'renter@example.com',
                'provider_message_id' => '<brevo-msg-delivered@smtp-relay.mailin.fr>',
                'message_id' => null,
            ],
        ]);

        $this->seedOutboundMessage(
            providerMessageId: '<brevo-msg-delivered@smtp-relay.mailin.fr>',
            status: MessageStatus::Sent,
            contact: $contact,
            source: MessageSource::Playbook,
            sourceRef: [
                'automation_id' => $automation->id,
                'automation_run_id' => $run->id,
                'automation_run_step_id' => $step->id,
                'playbook_id' => $playbook->id,
            ],
        );

        $this->postJson(
            "/api/webhooks/brevo/{$this->webhookToken}",
            $this->fixture('brevo_delivered.json'),
        )->assertOk();

        $step->refresh();
        $this->assertSame('delivered', $step->output['delivery_status'] ?? null);
        $this->assertSame('delivered', $step->output['delivery_raw_status'] ?? null);
        $this->assertArrayHasKey('delivered_at', $step->output ?? []);
        $this->assertSame('renter@example.com', $step->output['to'] ?? null);
    }

    public function test_classification_events(): void
    {
        Event::fake([ChannelDeliveryFailed::class]);

        $hardMessage = $this->seedOutboundMessage(
            providerMessageId: '<brevo-msg-bounce@smtp-relay.mailin.fr>',
            status: MessageStatus::Sent,
            to: 'gone@example.com',
        );

        $this->postJson(
            "/api/webhooks/brevo/{$this->webhookToken}",
            $this->fixture('brevo_hard_bounce.json'),
        )->assertOk();

        Event::assertDispatched(ChannelDeliveryFailed::class, function (ChannelDeliveryFailed $e) use ($hardMessage): bool {
            return $e->messageId === $hardMessage->id
                && $e->isPermanent === true
                && $e->status->value === 'bounced'
                && $e->address === 'gone@example.com';
        });

        Event::fake([ChannelDeliveryFailed::class]);

        $softMessage = $this->seedOutboundMessage(
            providerMessageId: '<brevo-msg-soft@smtp-relay.mailin.fr>',
            status: MessageStatus::Sent,
            to: 'busy@example.com',
        );

        $this->postJson(
            "/api/webhooks/brevo/{$this->webhookToken}",
            $this->fixture('brevo_soft_bounce.json'),
        )->assertOk();

        Event::assertDispatched(ChannelDeliveryFailed::class, function (ChannelDeliveryFailed $e) use ($softMessage): bool {
            return $e->messageId === $softMessage->id
                && $e->isPermanent === false
                && $e->status->value === 'bounced';
        });

        $this->assertFalse(Schema::hasTable('channel_suppressions'));
    }

    public function test_unmatched_and_legacy_offer_delivery(): void
    {
        $offer = Offer::factory()->sent()->create();
        $delivery = OfferDelivery::query()->create([
            'offer_id' => $offer->id,
            'channel' => 'email',
            'recipient_address' => 'legacy@example.com',
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'provider_message_id' => '<brevo-msg-delivered@smtp-relay.mailin.fr>',
            'communication_account_id' => $this->account->id,
        ]);

        $this->postJson(
            "/api/webhooks/brevo/{$this->webhookToken}",
            $this->fixture('brevo_delivered.json'),
        )->assertOk();

        $row = CommsWebhookEvent::query()->firstOrFail();
        $this->assertSame('unmatched', $row->processing_status);
        $this->assertNull($row->message_id);

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->delivery_status);
        $this->assertNotNull($delivery->delivered_at);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'comms.webhook.unmatched')->exists(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    private function seedOutboundMessage(
        string $providerMessageId,
        MessageStatus $status,
        ?Contact $contact = null,
        MessageSource $source = MessageSource::Manual,
        ?array $sourceRef = null,
        string $to = 'renter@example.com',
    ): Message {
        $contact ??= Contact::factory()->create();

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
            'status' => $status,
            'body_text' => 'Hi',
            'body_html' => '<p>Hi</p>',
            'from_address' => 'desk@example.com',
            'to_address' => $to,
            'provider' => Provider::Brevo,
            'communication_account_id' => $this->account->id,
            'provider_message_id' => $providerMessageId,
            'source' => $source,
            'source_ref' => $sourceRef,
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
            'communication_account_id' => $this->account->id,
            'message_id' => $message->id,
        ]);

        return $message->fresh(['interaction', 'thread']) ?? $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/delivery/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
