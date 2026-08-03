<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Injectors;

use App\Jobs\ProcessInboundWebhookEvent;
use App\Models\CallIntent;
use App\Models\CallWrapup;
use App\Models\CommsWebhookEvent;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use Database\Seeders\Demo\DemoWorld;

/**
 * Fabricates Aircall call lifecycle payloads and enters at ProcessInboundWebhookEvent.
 */
final class AircallInjector
{
    private int $callSeq = 900_000;

    private bool $seqBootstrapped = false;

    public function __construct(private readonly DemoWorld $world) {}

    public function answeredInbound(string $from, ?string $note = null): Message
    {
        $callId = $this->nextCallId();
        $started = now()->timestamp;
        $this->dispatch($this->payload('call.created', $callId, 'inbound', $from, [
            'status' => 'initial',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => null,
            'duration' => 0,
            'user' => null,
        ]));
        $this->dispatch($this->payload('call.answered', $callId, 'inbound', $from, [
            'status' => 'answered',
            'started_at' => $started,
            'answered_at' => $started + 5,
            'ended_at' => null,
            'duration' => 0,
            'user' => $this->agentUser(),
        ]));
        $event = $this->dispatch($this->payload('call.ended', $callId, 'inbound', $from, [
            'status' => 'done',
            'started_at' => $started,
            'answered_at' => $started + 5,
            'ended_at' => $started + 45,
            'duration' => 40,
            'user' => $this->agentUser(),
            'recording' => "https://assets.aircall.io/calls/{$callId}/recording.mp3",
        ]));

        return $this->messageForCall($callId, $event);
    }

    public function missedInbound(string $from): Message
    {
        $callId = $this->nextCallId();
        $started = now()->timestamp;
        $this->dispatch($this->payload('call.created', $callId, 'inbound', $from, [
            'status' => 'initial',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => null,
            'duration' => 0,
            'user' => null,
            'missed_call_reason' => null,
        ]));
        $event = $this->dispatch($this->payload('call.ended', $callId, 'inbound', $from, [
            'status' => 'done',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => $started + 20,
            'duration' => 20,
            'user' => null,
            'missed_call_reason' => 'no_available_agent',
        ]));

        return $this->messageForCall($callId, $event);
    }

    public function voicemail(string $from): Message
    {
        $callId = $this->nextCallId();
        $started = now()->timestamp;
        $this->dispatch($this->payload('call.created', $callId, 'inbound', $from, [
            'status' => 'initial',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => null,
            'duration' => 0,
            'user' => null,
            'missed_call_reason' => 'no_available_agent',
        ]));
        $event = $this->dispatch($this->payload('call.voicemail_left', $callId, 'inbound', $from, [
            'status' => 'done',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => $started + 50,
            'duration' => 50,
            'user' => null,
            'missed_call_reason' => 'no_available_agent',
            'voicemail' => "https://assets.aircall.io/calls/{$callId}/voicemail.mp3",
            'asset' => "https://assets.aircall.io/calls/{$callId}",
        ]));

        return $this->messageForCall($callId, $event);
    }

    /**
     * Unknown number → unmatched → triage (no contact channel).
     */
    public function unknownMissed(string $from): CommsWebhookEvent
    {
        $callId = $this->nextCallId();
        $started = now()->timestamp;
        $this->dispatch($this->payload('call.created', $callId, 'inbound', $from, [
            'status' => 'initial',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => null,
            'duration' => 0,
            'user' => null,
        ]));

        return $this->dispatch($this->payload('call.ended', $callId, 'inbound', $from, [
            'status' => 'done',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => $started + 18,
            'duration' => 18,
            'user' => null,
            'missed_call_reason' => 'no_available_agent',
        ]));
    }

    public function answeredOutbound(string $to, ?CallIntent $intent = null): Message
    {
        $callId = $intent?->aircall_call_id !== null && $intent->aircall_call_id !== ''
            ? (int) $intent->aircall_call_id
            : $this->nextCallId();

        if ($intent !== null && ($intent->aircall_call_id === null || $intent->aircall_call_id === '')) {
            $intent->forceFill(['aircall_call_id' => (string) $callId])->save();
        }

        $started = now()->timestamp;
        $this->dispatch($this->payload('call.created', $callId, 'outbound', $to, [
            'status' => 'initial',
            'started_at' => $started,
            'answered_at' => null,
            'ended_at' => null,
            'duration' => 0,
            'user' => $this->agentUser(),
        ]));
        $this->dispatch($this->payload('call.answered', $callId, 'outbound', $to, [
            'status' => 'answered',
            'started_at' => $started,
            'answered_at' => $started + 4,
            'ended_at' => null,
            'duration' => 0,
            'user' => $this->agentUser(),
        ]));
        $event = $this->dispatch($this->payload('call.ended', $callId, 'outbound', $to, [
            'status' => 'done',
            'started_at' => $started,
            'answered_at' => $started + 4,
            'ended_at' => $started + 55,
            'duration' => 51,
            'user' => $this->agentUser(),
            'recording' => "https://assets.aircall.io/calls/{$callId}/recording.mp3",
        ]));

        return $this->messageForCall($callId, $event);
    }

    public function wrapup(
        Message $message,
        string $disposition,
        ?string $note = null,
        ?Employee $employee = null,
    ): CallWrapup {
        $employee ??= Employee::query()->orderBy('id')->firstOrFail();

        return CallWrapup::query()->create([
            'message_id' => $message->id,
            'disposition' => $disposition,
            'note' => $note,
            'employee_id' => $employee->id,
        ]);
    }

    public function requestIntent(Contact $contact, string $toNumber, ?Employee $employee = null): CallIntent
    {
        $employee ??= Employee::query()->orderBy('id')->firstOrFail();

        return CallIntent::query()->create([
            'employee_id' => $employee->id,
            'contact_id' => $contact->id,
            'to_number' => $toNumber,
            'context_type' => 'contact',
            'context_id' => $contact->id,
            'status' => CallIntent::STATUS_REQUESTED,
            'aircall_call_id' => (string) $this->nextCallId(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(
        string $event,
        int $callId,
        string $direction,
        string $rawDigits,
        array $overrides,
    ): array {
        $base = [
            'id' => $callId,
            'sid' => 'CA_demo_'.$callId,
            'direct_link' => "https://api.aircall.io/v1/calls/{$callId}",
            'direction' => $direction,
            'status' => 'initial',
            'missed_call_reason' => null,
            'started_at' => now()->timestamp,
            'answered_at' => null,
            'ended_at' => null,
            'duration' => 0,
            'voicemail' => null,
            'recording' => null,
            'asset' => null,
            'raw_digits' => $rawDigits,
            'user' => null,
            'contact' => null,
            'archived' => false,
            'number' => [
                'id' => 1234,
                'direct_link' => 'https://api.aircall.io/v1/numbers/1234',
                'name' => 'Main Line',
                'digits' => '+15550001111',
                'country' => 'ES',
                'time_zone' => 'Europe/Madrid',
                'open' => true,
            ],
        ];

        return [
            'resource' => 'call',
            'event' => $event,
            'timestamp' => now()->timestamp,
            'token' => 'demo-aircall-token',
            'data' => array_merge($base, $overrides),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $payload): CommsWebhookEvent
    {
        $account = $this->world->aircallAccount();
        // Must match AircallAdapter::parseInbound providerEventId ({callId}:{event}).
        $callId = (string) ($payload['data']['id'] ?? '');
        $event = (string) ($payload['event'] ?? 'call');
        $providerEventId = $callId.':'.$event;

        $row = CommsWebhookEvent::query()->create([
            'communication_account_id' => $account->id,
            'provider_event_id' => $providerEventId,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        app()->call([new ProcessInboundWebhookEvent($row->id), 'handle']);

        return $row->fresh() ?? $row;
    }

    private function messageForCall(int $callId, CommsWebhookEvent $event): Message
    {
        $message = Message::query()
            ->where('provider_message_id', (string) $callId)
            ->latest('id')
            ->first();

        if ($message === null) {
            throw new \RuntimeException(
                "Aircall injector produced no message for call {$callId} (webhook {$event->id})."
            );
        }

        return $message;
    }

    private function nextCallId(): int
    {
        if (! $this->seqBootstrapped) {
            $this->callSeq = max($this->callSeq, $this->highestUsedCallId());
            $this->seqBootstrapped = true;
        }

        $this->callSeq++;

        return $this->callSeq;
    }

    /**
     * Fresh injectors (e.g. --inbox-only) must continue past IDs already written
     * during the full seed — provider_event_id is unique per account.
     */
    private function highestUsedCallId(): int
    {
        $max = 0;

        $accountId = $this->world->aircallAccount()->id;
        $eventIds = CommsWebhookEvent::query()
            ->where('communication_account_id', $accountId)
            ->pluck('provider_event_id');

        foreach ($eventIds as $providerEventId) {
            if (preg_match('/^(\d+):/', (string) $providerEventId, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $messageIds = Message::query()
            ->whereNotNull('provider_message_id')
            ->whereHas('thread', fn ($q) => $q->where('channel', 'call'))
            ->pluck('provider_message_id');

        foreach ($messageIds as $providerMessageId) {
            if (ctype_digit((string) $providerMessageId)) {
                $max = max($max, (int) $providerMessageId);
            }
        }

        return $max;
    }

    /**
     * @return array<string, mixed>
     */
    private function agentUser(): array
    {
        return [
            'id' => 456,
            'direct_link' => 'https://api.aircall.io/v1/users/456',
            'name' => 'Demo Agent',
            'email' => 'agent@demo.unit-hq.test',
            'available' => true,
        ];
    }
}
