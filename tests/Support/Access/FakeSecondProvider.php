<?php

declare(strict_types=1);

namespace Tests\Support\Access;

use App\Enums\AccessCredentialMode;
use App\Enums\AccessEventType;
use App\Support\Access\AccessProvider;
use App\Support\Access\AccessVerificationException;
use App\Support\Access\AccessWebhookPayload;
use App\Support\Access\DiscoveredPoint;
use App\Support\Access\GrantRef;
use App\Support\Access\GrantSpec;

/**
 * Architecture-seam stand-in: proves a second adapter registers and
 * round-trips with zero changes outside adapter + registry.
 */
final class FakeSecondProvider implements AccessProvider
{
    public const PROVIDER_KEY = 'fake_second';

    /** @var array<string, true> */
    private array $grants = [];

    /** @param  array<string, mixed>  $credentials */
    public function __construct(
        private readonly array $credentials = [],
    ) {}

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
        if (($this->credentials['api_key'] ?? '') === '') {
            throw new AccessVerificationException('FakeSecondProvider requires api_key.');
        }
    }

    public function credentialModes(): array
    {
        return [AccessCredentialMode::Pin->value];
    }

    public function listPoints(): array
    {
        return [
            new DiscoveredPoint('fs-point-1', 'Fake second gate', 'gate'),
        ];
    }

    public function grant(GrantSpec $spec): GrantRef
    {
        $ref = 'fs-grant-'.bin2hex(random_bytes(4));
        $this->grants[$ref] = true;

        return new GrantRef($ref, '654321', 'fs-cred-1');
    }

    public function revoke(string $grantRef): void
    {
        unset($this->grants[$grantRef]);
    }

    public function listGrants(?string $pointRef = null): array
    {
        $out = [];
        foreach (array_keys($this->grants) as $ref) {
            $out[] = [
                'grant_ref' => $ref,
                'provider_point_id' => $pointRef ?? 'fs-point-1',
                'credential_ref' => 'fs-cred-1',
            ];
        }

        return $out;
    }

    public function parseWebhook(array $payload): AccessWebhookPayload
    {
        return new AccessWebhookPayload(
            providerEventId: (string) ($payload['event_id'] ?? 'fs_evt_1'),
            eventType: AccessEventType::Granted->value,
            providerPointId: (string) ($payload['provider_point_id'] ?? 'fs-point-1'),
            providerGrantId: (string) ($payload['grant_ref'] ?? ''),
            occurredAt: now(),
        );
    }

    public function registerWebhooks(string $webhookUrl): array
    {
        return ['fs-wh-1'];
    }

    public function deleteWebhooks(array $endpointIds): void
    {
        // no-op
    }
}
