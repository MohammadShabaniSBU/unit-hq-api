<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Enums\CommunicationProviderType;

/**
 * Contract every outbound communication provider adapter implements.
 * Adapters are pure I/O wrappers — no business rules, no persistence.
 */
interface CommunicationProvider
{
    public function type(): CommunicationProviderType;

    /**
     * Verify the API key is valid against the provider. Throws
     * CommunicationProviderException on any failure (network, auth, etc).
     */
    public function verifyCredentials(string $apiKey): void;

    /**
     * Register a webhook endpoint on the provider side and return its
     * provider-assigned id so it can be deleted later on rotate/disconnect.
     */
    public function registerWebhook(string $apiKey, string $url): string;

    public function removeWebhook(string $apiKey, string $providerWebhookId): void;
}
