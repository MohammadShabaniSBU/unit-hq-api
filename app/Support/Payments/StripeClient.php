<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient as SdkClient;
use Stripe\WebhookEndpoint;

/**
 * Thin wrapper around the Stripe SDK. All Stripe-specific API calls for
 * credential lifecycle live here so controllers stay provider-seam-clean
 * (S06-00 — provider === 'stripe' assumptions only in this class and
 * webhook signature verification).
 */
class StripeClient
{
    /**
     * @throws ApiErrorException
     */
    public function verifyBalance(string $secretKey): void
    {
        $this->sdk($secretKey)->balance->retrieve();
    }

    /**
     * @return array{id: string}
     *
     * @throws ApiErrorException
     */
    public function retrieveAccount(string $secretKey): array
    {
        $account = $this->sdk($secretKey)->accounts->retrieve();

        return ['id' => (string) $account->id];
    }

    /**
     * @param  list<string>  $enabledEvents
     * @return array{id: string, secret: string|null}
     *
     * @throws ApiErrorException
     */
    public function createWebhookEndpoint(string $secretKey, string $url, array $enabledEvents): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->sdk($secretKey)->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => $enabledEvents,
        ]);

        return [
            'id' => (string) $endpoint->id,
            'secret' => $endpoint->secret !== null ? (string) $endpoint->secret : null,
        ];
    }

    /**
     * Best-effort remote cleanup — callers swallow ApiErrorException.
     *
     * @throws ApiErrorException
     */
    public function deleteWebhookEndpoint(string $secretKey, string $endpointId): void
    {
        $this->sdk($secretKey)->webhookEndpoints->delete($endpointId);
    }

    private function sdk(string $secretKey): SdkClient
    {
        return new SdkClient($secretKey);
    }
}
