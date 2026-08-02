<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compute non-terminal active calls for the inbox badge banner (S12-01).
 *
 * Known contacts come from call Messages; unknowns from pending call triage rows.
 * Phase is derived from the last webhook event — no parallel state.
 */
final class ActiveCalls
{
    private const LOOKBACK_HOURS = 2;

    /**
     * @return list<array{
     *     direction: string,
     *     phase: 'ringing'|'ongoing',
     *     contact: array{id: int, name: string}|null,
     *     number: string,
     *     thread_id: int|null,
     *     triage_id: int|null,
     *     message_id: int|null,
     *     started_at: string|null,
     *     context_chips: list<array{type: string, delinquency_id?: int}>
     * }>
     */
    public static function forBadge(): array
    {
        $cutoff = now()->subHours(self::LOOKBACK_HOURS);

        $fromMessages = self::fromMessages($cutoff);
        $fromTriage = self::fromTriage($cutoff);

        return $fromMessages
            ->concat($fromTriage)
            ->sortBy(fn (array $row): string => $row['started_at'] ?? '')
            ->values()
            ->all();
    }

    /**
     * Map a webhook event / outcome to a banner phase, or null if terminal.
     */
    public static function phase(?string $event, ?string $outcome): ?string
    {
        if (in_array($event, ['call.ended', 'call.voicemail_left'], true)) {
            return null;
        }

        if ($event === 'call.answered') {
            return 'ongoing';
        }

        if ($event === 'call.created') {
            return 'ringing';
        }

        if ($outcome === 'voicemail' || ($outcome !== null && str_starts_with($outcome, 'missed'))) {
            return null;
        }

        if ($outcome === 'answered') {
            return 'ongoing';
        }

        if ($outcome === 'ringing') {
            return 'ringing';
        }

        return null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function fromMessages(Carbon $cutoff): Collection
    {
        $threadIds = MessageThread::query()
            ->where('channel', Channel::Call)
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_message_at', '>=', $cutoff)
                    ->orWhere('updated_at', '>=', $cutoff);
            })
            ->pluck('id');

        if ($threadIds->isEmpty()) {
            return collect();
        }

        $messages = Message::query()
            ->whereIn('message_thread_id', $threadIds)
            ->where('provider', Provider::Aircall)
            ->where('updated_at', '>=', $cutoff)
            ->with(['thread.contact'])
            ->orderByDesc('updated_at')
            ->get()
            // One row per call id (provider_message_id) — latest update wins.
            ->unique('provider_message_id')
            ->values();

        $contactIds = $messages
            ->map(fn (Message $m): ?int => $m->thread?->contact_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $overdueByContact = self::overdueChipsForContacts($contactIds);

        return $messages->map(function (Message $message) use ($overdueByContact): ?array {
            $ref = is_array($message->source_ref) ? $message->source_ref : [];
            $event = isset($ref['event']) && is_string($ref['event']) ? $ref['event'] : null;
            $outcome = isset($ref['outcome']) && is_string($ref['outcome']) ? $ref['outcome'] : null;
            $phase = self::phase($event, $outcome);
            if ($phase === null) {
                return null;
            }

            $thread = $message->thread;
            $contact = $thread?->contact;
            $direction = $message->direction instanceof MessageDirection
                ? $message->direction->value
                : (string) $message->direction;

            $number = $direction === MessageDirection::Outbound->value
                ? (string) $message->to_address
                : (string) $message->from_address;

            if ($number === '' && $thread?->channel_key !== null) {
                $number = (string) $thread->channel_key;
            }

            $startedAt = self::startedAtFromRef($ref, $message->sent_at ?? $message->created_at);
            $contactId = $contact !== null ? (int) $contact->id : null;

            return [
                'direction' => $direction,
                'phase' => $phase,
                'contact' => $contact !== null ? self::contactPayload($contact) : null,
                'number' => $number,
                'thread_id' => $thread !== null ? (int) $thread->id : null,
                'triage_id' => null,
                'message_id' => (int) $message->id,
                'started_at' => $startedAt,
                'context_chips' => $contactId !== null
                    ? ($overdueByContact[$contactId] ?? [])
                    : [],
            ];
        })->filter()->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function fromTriage(Carbon $cutoff): Collection
    {
        $rows = CommsTriage::query()
            ->where('status', 'pending')
            ->where('channel', Channel::Call)
            ->where('updated_at', '>=', $cutoff)
            ->orderByDesc('updated_at')
            ->get();

        return $rows->map(function (CommsTriage $triage): ?array {
            $payload = is_array($triage->payload) ? $triage->payload : [];
            $event = isset($payload['event']) && is_string($payload['event'])
                ? $payload['event']
                : null;
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $outcome = null;
            if ($event === 'call.created') {
                $outcome = 'ringing';
            } elseif ($event === 'call.answered') {
                $outcome = 'answered';
            }

            $phase = self::phase($event, $outcome);
            if ($phase === null) {
                return null;
            }

            $direction = isset($data['direction']) && is_string($data['direction'])
                ? $data['direction']
                : 'inbound';

            $number = (string) $triage->sender_value;
            if ($number === '' && isset($data['raw_digits']) && is_string($data['raw_digits'])) {
                $number = $data['raw_digits'];
            }

            $startedTs = isset($data['started_at']) && is_numeric($data['started_at'])
                ? (int) $data['started_at']
                : null;
            $startedAt = $startedTs !== null
                ? Carbon::createFromTimestampUTC($startedTs)->toIso8601String()
                : ($triage->created_at?->toIso8601String());

            return [
                'direction' => $direction,
                'phase' => $phase,
                'contact' => null,
                'number' => $number,
                'thread_id' => null,
                'triage_id' => (int) $triage->id,
                'message_id' => null,
                'started_at' => $startedAt,
                'context_chips' => [],
            ];
        })->filter()->values();
    }

    /**
     * @param  list<int>  $contactIds
     * @return array<int, list<array{type: string, delinquency_id: int}>>
     */
    private static function overdueChipsForContacts(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $contracts = Contract::query()
            ->whereIn('contact_id', $contactIds)
            ->get(['id', 'contact_id']);

        if ($contracts->isEmpty()) {
            return [];
        }

        /** @var array<int, int> $contractToContact */
        $contractToContact = $contracts->pluck('contact_id', 'id')->all();

        $openCases = Delinquency::query()
            ->open()
            ->whereIn('contract_id', array_keys($contractToContact))
            ->get(['id', 'contract_id']);

        $byContact = [];
        foreach ($openCases as $case) {
            $contactId = (int) ($contractToContact[(int) $case->contract_id] ?? 0);
            if ($contactId === 0) {
                continue;
            }

            $byContact[$contactId][] = [
                'type' => 'overdue',
                'delinquency_id' => (int) $case->id,
            ];
        }

        return $byContact;
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

    /**
     * @param  array<string, mixed>  $ref
     */
    private static function startedAtFromRef(array $ref, mixed $fallback): ?string
    {
        $call = is_array($ref['call'] ?? null) ? $ref['call'] : [];
        if (isset($call['started_at']) && is_numeric($call['started_at'])) {
            return Carbon::createFromTimestampUTC((int) $call['started_at'])->toIso8601String();
        }

        if ($fallback instanceof Carbon) {
            return $fallback->toIso8601String();
        }

        return $fallback !== null ? Carbon::parse((string) $fallback)->toIso8601String() : null;
    }
}
