<?php

declare(strict_types=1);

namespace App\Support\Access;

/**
 * Multi-adapter access-control capability interface (S15-01).
 */
interface AccessProvider
{
    /**
     * @return array<string, array{label: string, secret?: bool}>
     */
    public function credentialFields(): array;

    /**
     * Cheap authenticated call. Throws AccessVerificationException on failure.
     */
    public function verify(): void;

    /**
     * Credential modes this adapter (and installed hardware) supports.
     *
     * @return list<string> Values of AccessCredentialMode
     */
    public function credentialModes(): array;

    /**
     * Discover locks/gates at the provider.
     *
     * @return list<DiscoveredPoint>
     */
    public function listPoints(): array;

    public function grant(GrantSpec $spec): GrantRef;

    public function revoke(string $grantRef): void;

    /**
     * Current grants at the provider (drift-check input for S15-02).
     *
     * @return list<array{grant_ref: string, provider_point_id: string, credential_ref?: string|null}>
     */
    public function listGrants(?string $pointRef = null): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): AccessWebhookPayload;

    /**
     * Register inbound webhooks for the given public URL.
     *
     * @return list<string> Remote endpoint identifiers
     */
    public function registerWebhooks(string $webhookUrl): array;

    /**
     * Best-effort delete of previously registered webhook endpoints.
     *
     * @param  list<string>  $endpointIds
     */
    public function deleteWebhooks(array $endpointIds): void;
}
