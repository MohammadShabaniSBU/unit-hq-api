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
