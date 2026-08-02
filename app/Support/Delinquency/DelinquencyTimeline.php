<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Http\Resources\DelinquencyStepResource;
use App\Models\Delinquency;
use App\Models\DelinquencyStep;
use App\Models\Message;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\Provider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Case timeline = ladder/manual steps + correlated call messages with
 * context_type=delinquency (S12-01). Chronological interleave; no new tables.
 */
final class DelinquencyTimeline
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function interleaved(Delinquency $delinquency): array
    {
        $steps = $delinquency->timeline();

        $stepEntries = $steps->map(function (DelinquencyStep $step): array {
            $payload = DelinquencyStepResource::make($step)->resolve();
            $payload['entry_type'] = 'step';

            return [
                'sort_at' => self::stepSortAt($step),
                'entry' => $payload,
            ];
        });

        $callEntries = self::callMessagesForCase((int) $delinquency->id)
            ->map(function (Message $message): array {
                return [
                    'sort_at' => self::messageSortAt($message),
                    'entry' => self::mapCallEntry($message),
                ];
            });

        return $stepEntries
            ->concat($callEntries)
            ->sortBy([
                ['sort_at', 'asc'],
            ])
            ->values()
            ->map(fn (array $row): array => $row['entry'])
            ->all();
    }

    /**
     * @return Collection<int, Message>
     */
    private static function callMessagesForCase(int $delinquencyId): Collection
    {
        return Message::query()
            ->where('provider', Provider::Aircall)
            ->whereNotNull('source_ref')
            ->with(['thread', 'wrapup'])
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get()
            ->filter(function (Message $message) use ($delinquencyId): bool {
                $intent = is_array($message->source_ref)
                    ? ($message->source_ref['call_intent'] ?? null)
                    : null;
                if (! is_array($intent)) {
                    return false;
                }

                return ($intent['context_type'] ?? null) === 'delinquency'
                    && (int) ($intent['context_id'] ?? 0) === $delinquencyId;
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapCallEntry(Message $message): array
    {
        $ref = is_array($message->source_ref) ? $message->source_ref : [];
        $direction = $message->direction instanceof MessageDirection
            ? $message->direction->value
            : (string) $message->direction;

        $at = $message->sent_at ?? $message->created_at;

        return [
            'entry_type' => 'call',
            'id' => 'call-'.$message->id,
            'message_id' => (int) $message->id,
            'thread_id' => (int) $message->message_thread_id,
            'direction' => $direction,
            'outcome' => isset($ref['outcome']) && is_string($ref['outcome']) ? $ref['outcome'] : null,
            'duration' => isset($ref['duration']) && is_numeric($ref['duration']) ? (int) $ref['duration'] : null,
            'disposition' => $message->wrapup?->disposition,
            'body_text' => $message->body_text,
            'executed_on' => $at !== null ? Carbon::parse($at)->toDateString() : null,
            'created_at' => $at !== null ? Carbon::parse($at)->toIso8601String() : null,
            'call_intent' => is_array($ref['call_intent'] ?? null) ? $ref['call_intent'] : null,
        ];
    }

    private static function stepSortAt(DelinquencyStep $step): string
    {
        $date = $step->executed_on !== null
            ? Carbon::parse((string) $step->executed_on)->startOfDay()
            : ($step->created_at ?? now());

        // Keep same-day order stable via id after date.
        return $date->format('Y-m-d').'T00:00:00+00:00|step|'.str_pad((string) $step->id, 12, '0', STR_PAD_LEFT);
    }

    private static function messageSortAt(Message $message): string
    {
        $at = $message->sent_at ?? $message->created_at ?? now();

        return Carbon::parse($at)->toIso8601String().'|call|'.str_pad((string) $message->id, 12, '0', STR_PAD_LEFT);
    }
}
