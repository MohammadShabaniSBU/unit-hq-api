<?php

declare(strict_types=1);

namespace App\Support\ESign;

/**
 * Resolves the active e-sign provider for document rendering / envelopes.
 * v1: single fake until S14-02 wires Signable + accounts.
 */
final class ESignProviderRegistry
{
    private ?ESignProvider $provider = null;

    public function set(?ESignProvider $provider): void
    {
        $this->provider = $provider;
    }

    public function active(): ESignProvider
    {
        return $this->provider ?? new FakeESignProvider;
    }

    public function signatureAnchor(): string
    {
        return $this->active()->signatureAnchor();
    }
}
