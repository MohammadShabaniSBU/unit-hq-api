<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Enums\CommunicationProviderType;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Brevo (transactional email/SMS) adapter.
 *
 * Talks to Brevo's REST API directly (no official SDK dependency added —
 * the surface used here is three small calls). Structured for real use;
 * exercised in this codebase only via unit-testable seams since no live
 * Brevo account is configured for this deployment.
 */
final class BrevoAdapter implements CommunicationProvider
{
    private const BASE_URL = 'https://api.brevo.com/v3';

    public function type(): CommunicationProviderType
    {
        return CommunicationProviderType::Brevo;
    }

    public function verifyCredentials(string $apiKey): void
    {
        try {
            $response = Http::withHeaders(['api-key' => $apiKey])
                ->timeout(10)
                ->get(self::BASE_URL.'/account');
        } catch (Throwable $e) {
            throw new CommunicationProviderException('Could not reach Brevo: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new CommunicationProviderException(
                'Brevo rejected the API key ('.$response->status().').'
            );
        }
    }

    public function registerWebhook(string $apiKey, string $url): string
    {
        try {
            $response = Http::withHeaders(['api-key' => $apiKey])
                ->timeout(10)
                ->post(self::BASE_URL.'/webhooks', [
                    'url' => $url,
                    'type' => 'transactional',
                    'events' => ['delivered', 'hardBounce', 'softBounce', 'spam', 'blocked', 'unsubscribed'],
                ]);
        } catch (Throwable $e) {
            throw new CommunicationProviderException('Could not reach Brevo: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new CommunicationProviderException(
                'Brevo webhook registration failed ('.$response->status().').'
            );
        }

        $id = $response->json('id');

        if ($id === null) {
            throw new CommunicationProviderException('Brevo did not return a webhook id.');
        }

        return (string) $id;
    }

    public function removeWebhook(string $apiKey, string $providerWebhookId): void
    {
        try {
            Http::withHeaders(['api-key' => $apiKey])
                ->timeout(10)
                ->delete(self::BASE_URL.'/webhooks/'.$providerWebhookId);
        } catch (Throwable $e) {
            throw new CommunicationProviderException('Could not reach Brevo: '.$e->getMessage(), previous: $e);
        }
    }
}
