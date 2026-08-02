<?php

declare(strict_types=1);

namespace App\Support\ESign;

/**
 * Multi-adapter e-sign capability interface (S14-02 owns concrete adapters).
 * Document rendering (S14-01) only needs signatureAnchor().
 */
interface ESignProvider
{
    /**
     * @return list<array{key: string, label: string, secret?: bool}>
     */
    public function credentialFields(): array;

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
}
