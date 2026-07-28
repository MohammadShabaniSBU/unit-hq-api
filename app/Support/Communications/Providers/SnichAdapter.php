<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Enums\CommunicationProviderType;

/**
 * Snich (SMS/WhatsApp) adapter — stub only. No SDK or documented HTTP
 * surface exists for this provider yet, so credential verification and
 * webhook registration are structured but simulated. Swap the bodies of
 * these three methods for real HTTP calls once Snich API docs/SDK exist;
 * the CommunicationProvider contract and callers do not need to change.
 */
final class SnichAdapter implements CommunicationProvider
{
    public function type(): CommunicationProviderType
    {
        return CommunicationProviderType::Snich;
    }

    public function verifyCredentials(string $apiKey): void
    {
        if (trim($apiKey) === '') {
            throw new CommunicationProviderException('Snich API key cannot be blank.');
        }

        // TODO: replace with a real Snich account/auth check once available.
    }

    public function registerWebhook(string $apiKey, string $url): string
    {
        // TODO: replace with a real Snich webhook-registration call once available.
        return 'snich_stub_'.substr(hash('sha256', $apiKey.$url), 0, 24);
    }

    public function removeWebhook(string $apiKey, string $providerWebhookId): void
    {
        // TODO: replace with a real Snich webhook-removal call once available.
    }
}
