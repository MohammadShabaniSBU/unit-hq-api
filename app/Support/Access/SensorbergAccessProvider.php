<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessCredentialMode;
use App\Enums\AccessEventType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Sensorberg adapter stub (S15-01).
 *
 * Assumed shape until sandbox access confirms:
 * - OAuth2 client_credentials (client_id + client_secret + optional base_url)
 * - credentialModes: app_invite + pin (hardware-dependent in reality)
 * - Grants/points/webhooks under /v1/* relative to base_url
 *
 * Correct field names / grant scope / webhook catalogue in this class
 * (and 01-provider-and-sensorberg.md) once sandbox answers land — interface stays.
 */
final class SensorbergAccessProvider implements AccessProvider
{
    private const DEFAULT_BASE_URL = 'https://api.sensorberg.example/v1';

    /** @param  array<string, mixed>  $credentials */
    private function __construct(
        private readonly array $credentials,
    ) {}

    /** @param  array<string, mixed>  $credentials */
    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public function credentialFields(): array
    {
        return [
            'client_id' => ['label' => 'Client ID', 'secret' => false],
            'client_secret' => ['label' => 'Client secret', 'secret' => true],
            'base_url' => ['label' => 'API base URL (optional)', 'secret' => false],
        ];
    }

    public function verify(): void
    {
        $token = $this->accessToken();

        $response = $this->client($token)->get($this->baseUrl().'/points', [
            'limit' => 1,
        ]);

        if ($response->failed()) {
            throw new AccessVerificationException(
                'Sensorberg rejected the credentials ('.$response->status().').'
            );
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
        $response = $this->client($this->accessToken())->get($this->baseUrl().'/points');

        if ($response->failed()) {
            throw new AccessProviderException(
                'Sensorberg listPoints failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $items = $data['points'] ?? $data['data'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? $item['provider_point_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = new DiscoveredPoint(
                providerPointId: $id,
                label: (string) ($item['label'] ?? $item['name'] ?? $id),
                kindHint: isset($item['kind']) ? (string) $item['kind'] : (isset($item['kind_hint']) ? (string) $item['kind_hint'] : null),
            );
        }

        return $out;
    }

    public function grant(GrantSpec $spec): GrantRef
    {
        $payload = [
            'point_id' => $spec->providerPointId,
            'mode' => $spec->mode,
            'person' => $spec->person,
            'metadata' => $spec->metadata,
        ];

        $response = $this->client($this->accessToken())->post($this->baseUrl().'/grants', $payload);

        if ($response->failed()) {
            throw new AccessProviderException(
                'Sensorberg grant failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $ref = (string) ($data['id'] ?? $data['grant_id'] ?? '');
        if ($ref === '') {
            throw new AccessProviderException('Sensorberg grant returned no grant id.');
        }

        $pin = isset($data['pin']) && is_string($data['pin']) ? $data['pin'] : null;
        $credentialRef = isset($data['credential_ref']) ? (string) $data['credential_ref'] : null;

        return new GrantRef($ref, $pin, $credentialRef);
    }

    public function revoke(string $grantRef): void
    {
        $response = $this->client($this->accessToken())
            ->delete($this->baseUrl().'/grants/'.rawurlencode($grantRef));

        if ($response->failed()) {
            throw new AccessProviderException(
                'Sensorberg revoke failed ('.$response->status().'): '.$response->body()
            );
        }
    }

    public function listGrants(?string $pointRef = null): array
    {
        $query = [];
        if ($pointRef !== null) {
            $query['point_id'] = $pointRef;
        }

        $response = $this->client($this->accessToken())->get($this->baseUrl().'/grants', $query);

        if ($response->failed()) {
            throw new AccessProviderException(
                'Sensorberg listGrants failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $items = $data['grants'] ?? $data['data'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = (string) ($item['id'] ?? $item['grant_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $out[] = [
                'grant_ref' => $ref,
                'provider_point_id' => (string) ($item['point_id'] ?? $item['provider_point_id'] ?? ''),
                'credential_ref' => isset($item['credential_ref']) ? (string) $item['credential_ref'] : null,
            ];
        }

        return $out;
    }

    public function parseWebhook(array $payload): AccessWebhookPayload
    {
        $typeRaw = (string) ($payload['type'] ?? $payload['event_type'] ?? AccessWebhookPayload::TYPE_UNKNOWN);
        $type = match ($typeRaw) {
            'granted', 'access.granted', 'door.opened' => AccessEventType::Granted->value,
            'denied', 'access.denied', 'door.denied' => AccessEventType::Denied->value,
            default => AccessWebhookPayload::TYPE_UNKNOWN,
        };

        $eventId = (string) ($payload['id'] ?? $payload['event_id'] ?? '');
        if ($eventId === '') {
            $eventId = 'sensorberg:'.md5(json_encode($payload) ?: uniqid('sb', true));
        }

        $occurred = $payload['occurred_at'] ?? $payload['timestamp'] ?? null;
        $occurredAt = is_string($occurred) ? new \DateTimeImmutable($occurred) : now();

        return new AccessWebhookPayload(
            providerEventId: $eventId,
            eventType: $type,
            providerPointId: isset($payload['point_id'])
                ? (string) $payload['point_id']
                : (isset($payload['provider_point_id']) ? (string) $payload['provider_point_id'] : null),
            providerGrantId: isset($payload['grant_id'])
                ? (string) $payload['grant_id']
                : (isset($payload['grant_ref']) ? (string) $payload['grant_ref'] : null),
            providerCredentialRef: isset($payload['credential_ref']) ? (string) $payload['credential_ref'] : null,
            occurredAt: $occurredAt,
        );
    }

    public function registerWebhooks(string $webhookUrl): array
    {
        $response = $this->client($this->accessToken())->post($this->baseUrl().'/webhooks', [
            'url' => $webhookUrl,
            'events' => ['access.granted', 'access.denied'],
        ]);

        if ($response->failed()) {
            throw new AccessProviderException(
                'Sensorberg registerWebhooks failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $id = (string) ($data['id'] ?? $data['webhook_id'] ?? 'sb-wh-1');

        return [$id];
    }

    public function deleteWebhooks(array $endpointIds): void
    {
        foreach ($endpointIds as $id) {
            $this->client($this->accessToken())
                ->delete($this->baseUrl().'/webhooks/'.rawurlencode($id));
        }
    }

    private function baseUrl(): string
    {
        $base = $this->credentials['base_url'] ?? null;

        return is_string($base) && $base !== ''
            ? rtrim($base, '/')
            : self::DEFAULT_BASE_URL;
    }

    private function accessToken(): string
    {
        $clientId = (string) ($this->credentials['client_id'] ?? '');
        $clientSecret = (string) ($this->credentials['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            throw new AccessVerificationException('Sensorberg client_id and client_secret are required.');
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post($this->baseUrl().'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if ($response->failed()) {
            throw new AccessVerificationException(
                'Sensorberg token request failed ('.$response->status().').'
            );
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new AccessVerificationException('Sensorberg token response missing access_token.');
        }

        return $token;
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200);
    }
}
