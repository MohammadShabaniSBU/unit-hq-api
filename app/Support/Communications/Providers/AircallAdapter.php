<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DialResult;
use App\Support\Communications\Results\InboundMessage;
use App\Support\Communications\Results\VerificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Aircall call adapter: inbound lifecycle webhooks + outbound click-to-dial.
 * Media mirroring is out of scope (URL only).
 */
final class AircallAdapter implements ProviderAccount, ReceivesInbound
{
    private const BASE_URL = 'https://api.aircall.io/v1';

    private const EVENTS = [
        'call.created',
        'call.answered',
        'call.ended',
        'call.voicemail_left',
    ];

    /** @param  array<string, mixed>  $credentials */
    private function __construct(
        private readonly array $credentials,
    ) {}

    public static function make(array $credentials): static
    {
        return new self($credentials);
    }

    public function provider(): Provider
    {
        return Provider::Aircall;
    }

    public function channels(): array
    {
        return [Channel::Call];
    }

    public function credentialFields(): array
    {
        return [
            'api_id' => ['label' => 'API ID', 'secret' => false],
            'api_token' => ['label' => 'API token', 'secret' => true],
        ];
    }

    public function verify(): VerificationResult
    {
        $response = $this->client()->get(self::BASE_URL.'/ping');

        if ($response->failed()) {
            return VerificationResult::failed(
                'Aircall rejected the credentials ('.$response->status().').'
            );
        }

        return VerificationResult::ok();
    }

    /**
     * List Aircall users (all pages).
     *
     * @return list<array{id: string, label: string, email: string|null, availability_status: string|null}>
     *
     * @throws RuntimeException when the API rejects credentials or returns an unexpected body
     */
    public function listUsers(): array
    {
        $users = [];
        $page = 1;

        do {
            $response = $this->client()->get(self::BASE_URL.'/users', [
                'page' => $page,
                'per_page' => 50,
            ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Aircall user sync failed ('.$response->status().'). Check credentials in Settings → Communications.'
                );
            }

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $pageUsers = $body['users'] ?? [];
            if (! is_array($pageUsers)) {
                throw new RuntimeException('Aircall user sync returned an unexpected payload.');
            }

            foreach ($pageUsers as $user) {
                if (! is_array($user) || ! isset($user['id'])) {
                    continue;
                }

                $id = (string) $user['id'];
                $name = isset($user['name']) && is_string($user['name']) ? $user['name'] : '';
                $email = isset($user['email']) && is_string($user['email']) ? $user['email'] : null;
                $label = $name !== '' ? $name : ($email ?? $id);

                $users[] = [
                    'id' => $id,
                    'label' => $label,
                    'email' => $email,
                    'availability_status' => isset($user['availability_status']) && is_string($user['availability_status'])
                        ? $user['availability_status']
                        : null,
                ];
            }

            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $nextPageLink = $meta['next_page_link'] ?? null;
            $total = isset($meta['total']) ? (int) $meta['total'] : null;
            $page++;

            $done = ! is_string($nextPageLink) || $nextPageLink === ''
                || $pageUsers === []
                || ($total !== null && count($users) >= $total);
        } while (! $done);

        return array_values(collect($users)->unique('id')->values()->all());
    }

    /**
     * Granular availability for a user (`available`, `offline`, `in_call`, …).
     *
     * @throws RuntimeException when the API call fails
     */
    public function userAvailability(string $aircallUserId): string
    {
        $response = $this->client()->get(self::BASE_URL.'/users/'.$aircallUserId.'/availability');

        if ($response->failed()) {
            throw new RuntimeException(
                'Aircall availability check failed ('.$response->status().').'
            );
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        $availability = $body['availability'] ?? null;

        if (! is_string($availability) || $availability === '') {
            throw new RuntimeException('Aircall availability response was empty.');
        }

        return $availability;
    }

    /**
     * Click-to-dial: fill the user's Aircall Workspace dialer with `$toE164`.
     * Success is typically HTTP 204 with no call id body.
     */
    public function dial(string $aircallUserId, string $toE164): DialResult
    {
        $response = $this->client()->post(self::BASE_URL.'/users/'.$aircallUserId.'/dial', [
            'to' => $toE164,
        ]);

        /** @var array<string, mixed> $raw */
        $raw = is_array($response->json()) ? $response->json() : [];

        if ($response->status() === 204 || $response->successful()) {
            $callId = null;
            if (isset($raw['call']['id'])) {
                $callId = (string) $raw['call']['id'];
            } elseif (isset($raw['id'])) {
                $callId = (string) $raw['id'];
            }

            return DialResult::success($callId !== '' ? $callId : null, $raw);
        }

        if ($response->status() === 405) {
            return DialResult::failed(
                'Your Aircall device is offline or unavailable. Open Aircall Workspace and set yourself available, then try again.',
                'user_offline',
                $raw,
            );
        }

        if ($response->status() === 400) {
            return DialResult::failed(
                'Aircall rejected the number. Check the phone number is in E.164 format.',
                'invalid_number',
                $raw,
            );
        }

        return DialResult::failed(
            'Aircall dial failed ('.$response->status().'). Try again or check Settings → Communications.',
            'dial_failed',
            $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseInbound(array $payload): ?InboundMessage
    {
        $event = (string) ($payload['event'] ?? '');
        if (! in_array($event, self::EVENTS, true)) {
            return null;
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $callId = (string) ($data['id'] ?? '');
        if ($callId === '') {
            return null;
        }

        $directionRaw = strtolower((string) ($data['direction'] ?? 'inbound'));
        $direction = $directionRaw === 'outbound'
            ? MessageDirection::Outbound
            : MessageDirection::Inbound;

        $rawDigits = trim((string) ($data['raw_digits'] ?? ''));
        $ourNumber = '';
        $number = $data['number'] ?? null;
        if (is_array($number) && isset($number['digits']) && is_string($number['digits'])) {
            $ourNumber = trim($number['digits']);
        }

        if ($direction === MessageDirection::Inbound) {
            $from = $rawDigits;
            $to = $ourNumber;
        } else {
            $from = $ourNumber;
            $to = $rawDigits;
        }

        $agent = null;
        $user = $data['user'] ?? null;
        if (is_array($user) && isset($user['name']) && is_string($user['name'])) {
            $agent = $user['name'];
        }

        $duration = isset($data['duration']) ? (int) $data['duration'] : null;
        $missedReason = isset($data['missed_call_reason']) && is_string($data['missed_call_reason'])
            ? $data['missed_call_reason']
            : null;
        $answeredAt = $data['answered_at'] ?? null;
        $isMissedInbound = $direction === MessageDirection::Inbound
            && ($missedReason !== null || $answeredAt === null)
            && in_array($event, ['call.ended', 'call.voicemail_left'], true);

        // Answered inbound / outbound never bump unread; missed inbound + voicemail do.
        $countsAsUnread = $isMissedInbound;

        $outcome = match (true) {
            $event === 'call.voicemail_left' => 'voicemail',
            $isMissedInbound => 'missed'.($missedReason !== null ? " ({$missedReason})" : ''),
            $answeredAt !== null => 'answered',
            $event === 'call.answered' => 'answered',
            $event === 'call.created' => 'ringing',
            default => (string) ($data['status'] ?? $event),
        };

        $parts = [
            ucfirst($direction->value).' call',
            $outcome,
        ];
        if ($duration !== null && $duration > 0) {
            $parts[] = $duration.'s';
        }
        if ($agent !== null && $agent !== '') {
            $parts[] = 'agent '.$agent;
        }
        $body = implode(' · ', $parts);

        $recording = isset($data['recording']) && is_string($data['recording'])
            ? $data['recording']
            : null;
        $voicemail = isset($data['voicemail']) && is_string($data['voicemail'])
            ? $data['voicemail']
            : null;

        /** @var array<string, mixed> $sourceRef */
        $sourceRef = [
            'call' => $data,
            'event' => $event,
            'recording_url' => $recording,
            'voicemail_url' => $voicemail,
            'asset_url' => isset($data['asset']) && is_string($data['asset']) ? $data['asset'] : null,
            'duration' => $duration,
            'outcome' => $outcome,
            'agent' => $agent,
            'missed_call_reason' => $missedReason,
        ];

        $occurredAt = isset($payload['timestamp']) && is_numeric($payload['timestamp'])
            ? CarbonImmutable::createFromTimestampUTC((int) $payload['timestamp'])
            : CarbonImmutable::now();

        return new InboundMessage(
            providerMessageId: $callId,
            providerEventId: $callId.':'.$event,
            channel: Channel::Call,
            from: $from !== '' ? $from : 'anonymous',
            to: $to !== '' ? $to : 'unknown',
            subject: null,
            bodyText: $body,
            bodyHtml: null,
            headers: [],
            attachments: [],
            autoGenerated: false,
            occurredAt: $occurredAt,
            raw: $payload,
            direction: $direction,
            sourceRef: $sourceRef,
            countsAsUnread: $countsAsUnread,
        );
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) ($this->credentials['api_id'] ?? ''),
            (string) ($this->credentials['api_token'] ?? '')
        )
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }
}
