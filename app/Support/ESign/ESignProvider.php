<?php

declare(strict_types=1);

namespace App\Support\ESign;

/**
 * Multi-adapter e-sign capability interface (S14-02).
 */
interface ESignProvider
{
    /**
     * @return array<string, array{label: string, secret?: bool}>
     */
    public function credentialFields(): array;

    /**
     * Cheap authenticated call. Throws ESignVerificationException on failure.
     */
    public function verify(): void;

    /** Provider merge-field / placement token for signature_anchor blocks. */
    public function signatureAnchor(): string;

    public function createEnvelope(EnvelopeSpec $spec): EnvelopeRef;

    public function cancelEnvelope(string $ref): void;

    public function downloadSigned(string $ref): SignedResult;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ESignEvent;

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
