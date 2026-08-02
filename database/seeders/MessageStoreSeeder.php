<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Database\Seeder;

/**
 * Seeds mixed email/SMS thread history so the Inbox (S11) has shape from day one:
 * assignment, a suppressed address, a call thread, and an offer-sourced message —
 * every badge the S11-02 acceptance script expects to see rendered.
 * No provider calls — rows are inserted directly.
 */
class MessageStoreSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = Contact::query()->orderBy('id')->limit(5)->get();
        $manager = Employee::query()->where('role', 'manager')->first();

        foreach ($contacts as $index => $contact) {
            $email = ContactChannel::query()
                ->where('contact_id', $contact->id)
                ->where('type', ContactChannelType::Email)
                ->where('is_primary', true)
                ->value('value')
                ?? $contact->email
                ?? "contact{$contact->id}@example.com";

            $phone = ContactChannel::query()
                ->where('contact_id', $contact->id)
                ->where('type', ContactChannelType::Phone)
                ->where('is_primary', true)
                ->value('value')
                ?? sprintf('+1555000%04d', $contact->id % 10000);

            $emailThread = MessageThread::query()->create([
                'contact_id' => $contact->id,
                'channel' => Channel::Email,
                'subject' => 'Welcome to Unit HQ',
                'channel_key' => null,
                'last_message_at' => now()->subDays(3 - ($index % 3)),
                'last_inbound_at' => now()->subDays(2),
                'assigned_employee_id' => $index === 0 ? $manager?->id : null,
                'unread_count' => 1,
            ]);

            // One suppressed address so the list surfaces the composer pre-warning
            // micro-icon without touching the sender pipeline.
            if ($index === 1) {
                SuppressionWriter::write(
                    channel: Channel::Email,
                    address: $email,
                    scope: SuppressionScope::All,
                    reason: SuppressionReason::HardBounce,
                );
            }

            $outbound = $this->writeMessage(
                thread: $emailThread,
                direction: MessageDirection::Outbound,
                status: MessageStatus::Sent,
                from: 'desk@example.com',
                to: $email,
                text: 'Thanks for getting in touch — here are next steps.',
                html: '<p>Thanks for getting in touch — here are next steps.</p>',
                source: MessageSource::Manual,
                sentAt: now()->subDays(3),
            );

            $inbound = $this->writeMessage(
                thread: $emailThread,
                direction: MessageDirection::Inbound,
                status: MessageStatus::Received,
                from: $email,
                to: 'desk@example.com',
                text: 'Sounds good, when can we visit?',
                html: '<p>Sounds good, when can we visit?</p>',
                source: MessageSource::System,
                sentAt: now()->subDays(2),
                sourceRef: ['seed' => true],
            );

            $this->linkInteraction($contact, Channel::Email, $outbound, 'Welcome to Unit HQ');
            $this->linkInteraction($contact, Channel::Email, $inbound, 'Welcome to Unit HQ', 'inbound');

            // One offer-sourced message so the conversation source badge
            // ("Offer #45") links back to the real offer (02's acceptance script).
            $offer = Offer::query()->where('contact_id', $contact->id)->orderBy('id')->first()
                ?? Offer::query()->orderBy('id')->first();

            if ($offer !== null) {
                $offerMessage = $this->writeMessage(
                    thread: $emailThread,
                    direction: MessageDirection::Outbound,
                    status: MessageStatus::Delivered,
                    from: 'desk@example.com',
                    to: $email,
                    text: 'Your offer is ready — take a look and pick the unit that fits.',
                    html: '<p>Your offer is ready — take a look and pick the unit that fits.</p>',
                    source: MessageSource::Offer,
                    sentAt: now()->subDays(2)->addHours(3),
                    sourceRef: ['offer_id' => $offer->id],
                );

                $this->linkInteraction($contact, Channel::Email, $offerMessage, 'Offer sent');
            }

            $smsThread = MessageThread::query()->create([
                'contact_id' => $contact->id,
                'channel' => Channel::Sms,
                'subject' => null,
                'channel_key' => $phone,
                'last_message_at' => now()->subDay(),
                'last_inbound_at' => null,
                'unread_count' => 0,
            ]);

            $smsOut = $this->writeMessage(
                thread: $smsThread,
                direction: MessageDirection::Outbound,
                status: MessageStatus::Sent,
                from: '+15550001111',
                to: $phone,
                text: 'Reminder: your reservation hold expires tomorrow.',
                html: null,
                source: MessageSource::Playbook,
                sentAt: now()->subDay(),
                sourceRef: ['seed' => true, 'playbook' => 'lead-chase'],
            );

            $this->linkInteraction($contact, Channel::Sms, $smsOut, null);

            // One call thread (read-only in 02) so the call card + disabled
            // callback affordance and missed-call unread rule have a fixture.
            if ($index === 3) {
                $callThread = MessageThread::query()->create([
                    'contact_id' => $contact->id,
                    'channel' => Channel::Call,
                    'subject' => null,
                    'channel_key' => $phone,
                    'last_message_at' => now()->subHours(4),
                    'last_inbound_at' => now()->subHours(4),
                    'unread_count' => 1,
                ]);

                $this->writeMessage(
                    thread: $callThread,
                    direction: MessageDirection::Inbound,
                    status: MessageStatus::Received,
                    from: $phone,
                    to: '+15550001111',
                    text: 'Inbound call · missed (no answer) · 0s',
                    html: null,
                    source: MessageSource::System,
                    sentAt: now()->subHours(4),
                    sourceRef: [
                        'call' => ['seed' => true],
                        'event' => 'call.ended',
                        'recording_url' => null,
                        'voicemail_url' => null,
                        'asset_url' => null,
                        'duration' => 0,
                        'outcome' => 'missed (no answer)',
                        'agent' => null,
                        'missed_call_reason' => 'no answer',
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    private function writeMessage(
        MessageThread $thread,
        MessageDirection $direction,
        MessageStatus $status,
        string $from,
        string $to,
        ?string $text,
        ?string $html,
        MessageSource $source,
        mixed $sentAt,
        ?array $sourceRef = null,
    ): Message {
        return Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => $direction,
            'status' => $status,
            'body_text' => $text,
            'body_html' => $html,
            'from_address' => $from,
            'to_address' => $to,
            'provider' => null,
            'communication_account_id' => null,
            'provider_message_id' => null,
            'threading_evidence' => [
                'strategy' => $thread->channel === Channel::Email ? 'subject' : 'channel_key',
                'seed' => true,
            ],
            'source' => $source,
            'source_ref' => $sourceRef,
            'sent_at' => $direction === MessageDirection::Outbound ? $sentAt : null,
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
        ]);
    }

    private function linkInteraction(
        Contact $contact,
        Channel $channel,
        Message $message,
        ?string $summary,
        string $direction = 'outbound',
    ): void {
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => $channel->value,
            'direction' => $direction,
            'occurred_at' => $message->sent_at ?? $message->created_at,
            'content' => $message->body_text,
            'summary' => $summary,
            'metadata' => ['seed' => true],
            'message_id' => $message->id,
        ]);
    }
}
