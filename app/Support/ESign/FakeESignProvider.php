<?php

declare(strict_types=1);

namespace App\Support\ESign;

use LogicException;

/**
 * Default/test provider until Signable lands in S14-02.
 * signatureAnchor() is the only method document rendering needs.
 */
final class FakeESignProvider implements ESignProvider
{
    public const ANCHOR_TOKEN = '{{signature}}';

    public function credentialFields(): array
    {
        return [];
    }

    public function verify(): void
    {
        // no-op for fake
    }

    public function signatureAnchor(): string
    {
        return self::ANCHOR_TOKEN;
    }

    public function createEnvelope(EnvelopeSpec $spec): EnvelopeRef
    {
        throw new LogicException('FakeESignProvider::createEnvelope is not available until S14-02.');
    }

    public function cancelEnvelope(string $ref): void
    {
        throw new LogicException('FakeESignProvider::cancelEnvelope is not available until S14-02.');
    }

    public function downloadSigned(string $ref): SignedResult
    {
        throw new LogicException('FakeESignProvider::downloadSigned is not available until S14-02.');
    }

    public function parseWebhook(array $payload): ESignEvent
    {
        throw new LogicException('FakeESignProvider::parseWebhook is not available until S14-02.');
    }
}
