<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessCredentialMode;
use App\Enums\AccessEventType;

/**
 * Default / test provider. Full stub round-trip for automated tests.
 */
final class FakeAccessProvider implements AccessProvider
{
    /** @var list<array{provider_point_id: string, label: string, kind_hint: string|null}> */
    private static array $points = [];

    /** @var array<string, array{point: string, person: array<string, mixed>, mode: string, credential_ref: string, pin: string|null}> */
    private static array $grants = [];

    private static ?string $failNextGrant = null;

    private static ?string $failNextRevoke = null;

    /** @param  array<string, mixed>  $credentials */
    public function __construct(
        private readonly array $credentials = [],
    ) {}

    public static function reset(): void
    {
        self::$points = [
            [
                'provider_point_id' => 'fake-gate-1',
                'label' => 'Main gate',
                'kind_hint' => 'gate',
            ],
            [
                'provider_point_id' => 'fake-door-al6-06',
                'label' => 'Unit AL6-06 door',
                'kind_hint' => 'unit_door',
            ],
        ];
        self::$grants = [];
        self::$failNextGrant = null;
        self::$failNextRevoke = null;
    }

    public static function failNextGrant(?string $message = 'Simulated grant failure'): void
    {
        self::$failNextGrant = $message;
    }

    public static function failNextRevoke(?string $message = 'Simulated revoke failure'): void
    {
        self::$failNextRevoke = $message;
    }

    /**
     * Inject a provider-side grant the reconciler does not know about (drift).
     */
    public static function injectGrant(
        string $ref,
        string $providerPointId,
        ?string $credentialRef = null,
    ): void {
        self::$grants[$ref] = [
            'point' => $providerPointId,
            'person' => ['name' => 'Unknown'],
            'mode' => AccessCredentialMode::AppInvite->value,
            'credential_ref' => $credentialRef ?? 'cred-injected',
            'pin' => null,
        ];
    }

    public static function dropGrant(string $ref): void
    {
        unset(self::$grants[$ref]);
    }

    /** @param  array<string, mixed>  $credentials */
    public static function make(array $credentials = []): self
    {
        return new self($credentials);
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true],
        ];
    }

    public function verify(): void
    {
        $key = (string) ($this->credentials['api_key'] ?? '');
        if ($key === '' || str_starts_with($key, 'bad_')) {
            throw new AccessVerificationException('Fake provider rejected the API key.');
        }
    }

    public function credentialModes(): array
    {
        return [
            AccessCredentialMode::AppInvite->value,
            AccessCredentialMode::Pin->value,
        ];
    }

    public function listPoints(): array
    {
        if (self::$points === []) {
            self::reset();
        }

        return array_map(
            fn (array $p): DiscoveredPoint => new DiscoveredPoint(
                $p['provider_point_id'],
                $p['label'],
                $p['kind_hint'],
            ),
            self::$points,
        );
    }

    public function grant(GrantSpec $spec): GrantRef
    {
        if (self::$failNextGrant !== null) {
            $message = self::$failNextGrant;
            self::$failNextGrant = null;
            throw new AccessProviderException($message);
        }

        if ($spec->mode === AccessCredentialMode::AppInvite->value) {
            $email = $spec->person['email'] ?? null;
            if (! is_string($email) || $email === '') {
                throw new AccessProviderException('app_invite mode requires person.email.');
            }
        }

        $ref = 'fake-grant-'.bin2hex(random_bytes(6));
        $pin = $spec->mode === AccessCredentialMode::Pin->value
            ? (string) random_int(100000, 999999)
            : null;
        $credentialRef = 'cred-'.bin2hex(random_bytes(4));

        self::$grants[$ref] = [
            'point' => $spec->providerPointId,
            'person' => $spec->person,
            'mode' => $spec->mode,
            'credential_ref' => $credentialRef,
            'pin' => $pin,
        ];

        return new GrantRef($ref, $pin, $credentialRef);
    }

    public function revoke(string $grantRef): void
    {
        if (self::$failNextRevoke !== null) {
            $message = self::$failNextRevoke;
            self::$failNextRevoke = null;
            throw new AccessProviderException($message);
        }

        if (! isset(self::$grants[$grantRef])) {
            throw new AccessProviderException('Unknown grant: '.$grantRef);
        }

        unset(self::$grants[$grantRef]);
    }

    public function listGrants(?string $pointRef = null): array
    {
        $out = [];
        foreach (self::$grants as $ref => $grant) {
            if ($pointRef !== null && $grant['point'] !== $pointRef) {
                continue;
            }
            $out[] = [
                'grant_ref' => $ref,
                'provider_point_id' => $grant['point'],
                'credential_ref' => $grant['credential_ref'],
            ];
        }

        return $out;
    }

    public function parseWebhook(array $payload): AccessWebhookPayload
    {
        $type = (string) ($payload['type'] ?? $payload['event_type'] ?? AccessWebhookPayload::TYPE_UNKNOWN);
        if ($type === 'access.granted') {
            $type = AccessEventType::Granted->value;
        } elseif ($type === 'access.denied') {
            $type = AccessEventType::Denied->value;
        }

        $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
        if ($eventId === '') {
            $eventId = 'fake:'.md5(json_encode($payload) ?: uniqid('access', true));
        }

        $occurred = $payload['occurred_at'] ?? null;
        $occurredAt = is_string($occurred) ? new \DateTimeImmutable($occurred) : now();

        return new AccessWebhookPayload(
            providerEventId: $eventId,
            eventType: in_array($type, [AccessEventType::Granted->value, AccessEventType::Denied->value], true)
                ? $type
                : AccessWebhookPayload::TYPE_UNKNOWN,
            providerPointId: isset($payload['provider_point_id']) ? (string) $payload['provider_point_id'] : null,
            providerGrantId: isset($payload['grant_ref']) ? (string) $payload['grant_ref'] : null,
            providerCredentialRef: isset($payload['credential_ref']) ? (string) $payload['credential_ref'] : null,
            occurredAt: $occurredAt,
        );
    }

    public function registerWebhooks(string $webhookUrl): array
    {
        return ['fake-wh-1'];
    }

    public function deleteWebhooks(array $endpointIds): void
    {
        // no-op
    }
}
