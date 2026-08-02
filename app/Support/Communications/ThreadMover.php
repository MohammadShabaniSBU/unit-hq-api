<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\LogChannel;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SystemEvent;
use App\Support\RecordsActivity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Move a message to another thread with an audit trail.
 */
final class ThreadMover
{
    public static function move(Message $message, MessageThread $target, ?object $causer = null): Message
    {
        $fromThreadId = (int) $message->message_thread_id;

        if ($fromThreadId === (int) $target->id) {
            throw new InvalidArgumentException('Message is already on that thread.');
        }

        if ($message->thread === null) {
            $message->load('thread');
        }

        $fromThread = $message->thread;
        if ($fromThread === null) {
            throw new InvalidArgumentException('Message has no source thread.');
        }

        if ($fromThread->channel !== $target->channel) {
            throw new InvalidArgumentException('Cannot move a message across channels.');
        }

        if ((int) $fromThread->contact_id !== (int) $target->contact_id) {
            throw new InvalidArgumentException('Cannot move a message to another contact\'s thread.');
        }

        return self::performMove($message, $fromThread, $target, $fromThreadId, $causer);
    }

    /**
     * Create a fresh thread for the message's contact+channel and move onto it.
     * Email only — SMS/call threads are unique per channel_key.
     */
    public static function moveToNewThread(Message $message, ?object $causer = null): Message
    {
        if ($message->thread === null) {
            $message->load('thread');
        }

        $fromThread = $message->thread;
        if ($fromThread === null) {
            throw new InvalidArgumentException('Message has no source thread.');
        }

        $channel = $fromThread->channel instanceof Channel
            ? $fromThread->channel
            : Channel::from((string) $fromThread->channel);

        if ($channel !== Channel::Email) {
            throw new InvalidArgumentException(
                'New thread is only supported for email. SMS and call use a single thread per number.',
            );
        }

        $subject = $fromThread->subject;
        if (($subject === null || $subject === '') && is_array($message->threading_evidence)) {
            $subject = isset($message->threading_evidence['subject'])
                && is_string($message->threading_evidence['subject'])
                ? $message->threading_evidence['subject']
                : null;
        }

        $target = MessageThread::query()->create([
            'contact_id' => $fromThread->contact_id,
            'channel' => Channel::Email,
            'subject' => $subject !== null && $subject !== '' ? $subject : 'Moved message',
            'channel_key' => null,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        return self::move($message, $target, $causer);
    }

    private static function performMove(
        Message $message,
        MessageThread $fromThread,
        MessageThread $target,
        int $fromThreadId,
        ?object $causer,
    ): Message {
        return DB::transaction(function () use ($message, $target, $fromThread, $fromThreadId, $causer): Message {
            $message->forceFill([
                'message_thread_id' => $target->id,
                'threading_evidence' => array_merge(
                    is_array($message->threading_evidence) ? $message->threading_evidence : [],
                    [
                        'rethreaded' => true,
                        'from_thread_id' => $fromThreadId,
                        'to_thread_id' => $target->id,
                        'rethreaded_at' => now()->toIso8601String(),
                    ],
                ),
            ])->save();

            self::recomputeRollups($fromThread);
            self::recomputeRollups($target->fresh() ?? $target);

            SystemEvent::record('message.rethreaded', $message, [
                'message_id' => $message->id,
                'from_thread_id' => $fromThreadId,
                'to_thread_id' => $target->id,
            ]);

            RecordsActivity::log(
                LogChannel::Crm,
                'message.rethreaded',
                $message,
                [
                    'from_thread_id' => $fromThreadId,
                    'to_thread_id' => $target->id,
                ],
                $causer instanceof \Illuminate\Database\Eloquent\Model ? $causer : null,
            );

            return $message->fresh(['thread']) ?? $message;
        });
    }

    private static function recomputeRollups(MessageThread $thread): void
    {
        $lastMessageAt = Message::query()
            ->where('message_thread_id', $thread->id)
            ->max('created_at');

        $lastInboundAt = Message::query()
            ->where('message_thread_id', $thread->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('auto_generated', false)
            ->max('created_at');

        $unread = (int) Message::query()
            ->where('message_thread_id', $thread->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('auto_generated', false)
            ->count();

        // Unread is a running counter for S11; after a move, recompute as inbound count
        // (S11 will introduce read-state). Honest floor: non-auto inbound total.
        $thread->forceFill([
            'last_message_at' => $lastMessageAt ?? $thread->created_at ?? now(),
            'last_inbound_at' => $lastInboundAt,
            'unread_count' => $unread,
        ])->save();
    }
}
