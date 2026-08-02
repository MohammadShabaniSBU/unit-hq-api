<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\CallIntent;
use App\Models\CallWrapup;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * Recently ended correlated outbound calls awaiting wrap-up for this employee (S12-02).
 */
final class PendingWrapups
{
    private const LOOKBACK_HOURS = 2;

    /**
     * @return list<array{
     *     message_id: int,
     *     thread_id: int|null,
     *     contact: array{id: int, name: string}|null,
     *     number: string,
     *     ended_at: string|null
     * }>
     */
    public static function forEmployee(Employee $employee): array
    {
        $cutoff = now()->subHours(self::LOOKBACK_HOURS);

        $intents = CallIntent::query()
            ->where('employee_id', $employee->id)
            ->where('status', CallIntent::STATUS_CORRELATED)
            ->whereNotNull('message_id')
            ->where('updated_at', '>=', $cutoff)
            ->orderByDesc('updated_at')
            ->get();

        if ($intents->isEmpty()) {
            return [];
        }

        $messageIds = $intents->pluck('message_id')->filter()->unique()->values()->all();
        $wrappedIds = CallWrapup::query()
            ->whereIn('message_id', $messageIds)
            ->pluck('message_id')
            ->all();
        $wrappedSet = array_fill_keys($wrappedIds, true);

        $messages = Message::query()
            ->whereIn('id', $messageIds)
            ->with(['thread.contact'])
            ->get()
            ->keyBy('id');

        $pending = [];
        foreach ($intents as $intent) {
            $messageId = (int) $intent->message_id;
            if (isset($wrappedSet[$messageId])) {
                continue;
            }

            $message = $messages->get($messageId);
            if ($message === null || ! self::isTerminal($message)) {
                continue;
            }

            $thread = $message->thread;
            $contact = $thread?->contact;
            $number = (string) $message->to_address;
            if ($number === '' && $thread?->channel_key !== null) {
                $number = (string) $thread->channel_key;
            }

            $pending[] = [
                'message_id' => $messageId,
                'thread_id' => $thread !== null ? (int) $thread->id : null,
                'contact' => $contact !== null ? self::contactPayload($contact) : null,
                'number' => $number,
                'ended_at' => self::endedAt($message),
            ];
        }

        return $pending;
    }

    public static function isTerminal(Message $message): bool
    {
        $ref = is_array($message->source_ref) ? $message->source_ref : [];
        $event = isset($ref['event']) && is_string($ref['event']) ? $ref['event'] : null;
        $outcome = isset($ref['outcome']) && is_string($ref['outcome']) ? $ref['outcome'] : null;

        if (in_array($event, ['call.ended', 'call.voicemail_left'], true)) {
            return true;
        }

        if ($outcome === 'voicemail') {
            return true;
        }

        if ($outcome !== null && str_starts_with($outcome, 'missed')) {
            return true;
        }

        if ($outcome === 'answered' && $event !== 'call.answered' && $event !== 'call.created') {
            return true;
        }

        return false;
    }

    /**
     * @return array{id: int, name: string}
     */
    private static function contactPayload(Contact $contact): array
    {
        return [
            'id' => (int) $contact->id,
            'name' => trim($contact->first_name.' '.$contact->last_name),
        ];
    }

    private static function endedAt(Message $message): ?string
    {
        $ref = is_array($message->source_ref) ? $message->source_ref : [];
        $call = is_array($ref['call'] ?? null) ? $ref['call'] : [];
        if (isset($call['ended_at']) && is_numeric($call['ended_at'])) {
            return Carbon::createFromTimestampUTC((int) $call['ended_at'])->toIso8601String();
        }

        $at = $message->sent_at ?? $message->updated_at ?? $message->created_at;

        return $at !== null ? Carbon::parse($at)->toIso8601String() : null;
    }
}
