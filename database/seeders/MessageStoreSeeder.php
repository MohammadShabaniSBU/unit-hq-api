<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds mixed email/SMS thread history so the Inbox (S11) has shape from day one.
 * No provider calls — rows are inserted directly.
 */
class MessageStoreSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = Contact::query()->orderBy('id')->limit(5)->get();

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
                'unread_count' => 1,
            ]);

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
