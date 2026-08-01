<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use Illuminate\Support\Carbon;

trait SeedsInboxThreads
{
    /**
     * @param  array<string, mixed>  $threadOverrides
     * @param  array<string, mixed>  $messageOverrides
     */
    protected function makeInboxThread(
        Contact $contact,
        array $threadOverrides = [],
        array $messageOverrides = [],
    ): MessageThread {
        $at = $threadOverrides['last_message_at'] ?? now();

        $thread = MessageThread::query()->create(array_merge([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Inbox subject',
            'channel_key' => null,
            'last_message_at' => $at,
            'last_inbound_at' => null,
            'assigned_employee_id' => null,
            'unread_count' => 0,
        ], $threadOverrides));

        Message::query()->create(array_merge([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Inbound,
            'status' => MessageStatus::Received,
            'body_text' => 'Preview body for the inbox list.',
            'body_html' => null,
            'from_address' => $contact->email ?? 'renter@example.com',
            'to_address' => 'desk@example.com',
            'source' => MessageSource::System,
            'auto_generated' => false,
            'sent_at' => $at instanceof Carbon ? $at : Carbon::parse((string) $at),
        ], $messageOverrides));

        return $thread->fresh();
    }
}
