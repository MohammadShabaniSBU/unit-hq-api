<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient as SdkClient;
use Stripe\WebhookEndpoint;

/**
 * Thin wrapper around the Stripe SDK. All Stripe-specific API calls live here
 * so controllers stay provider-seam-clean (S06-00 — provider === 'stripe'
 * assumptions only in this class and webhook signature verification).
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
     * @param  array{name?: string|null, email?: string|null, metadata?: array<string, string>}  $params
     * @return array{id: string}
     *
     * @throws ApiErrorException
     */
    public function createCustomer(string $secretKey, array $params): array
    {
        /** @var Customer $customer */
        $customer = $this->sdk($secretKey)->customers->create(array_filter([
            'name' => $params['name'] ?? null,
            'email' => $params['email'] ?? null,
            'metadata' => $params['metadata'] ?? null,
        ], fn (mixed $v): bool => $v !== null));

        return ['id' => (string) $customer->id];
    }

    /**
     * @param  array{customer: string, usage?: string, metadata?: array<string, string>}  $params
     * @return array{id: string, client_secret: string|null}
     *
     * @throws ApiErrorException
     */
    public function createSetupIntent(string $secretKey, array $params): array
    {
        /** @var SetupIntent $intent */
        $intent = $this->sdk($secretKey)->setupIntents->create([
            'customer' => $params['customer'],
            'usage' => $params['usage'] ?? 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => $params['metadata'] ?? [],
        ]);

        return [
            'id' => (string) $intent->id,
            'client_secret' => $intent->client_secret !== null ? (string) $intent->client_secret : null,
        ];
    }

    /**
     * @return array{id: string, type: string|null, card: array{brand: string|null, last4: string|null, exp_month: int|null, exp_year: int|null}|null}
     *
     * @throws ApiErrorException
     */
    public function retrievePaymentMethod(string $secretKey, string $paymentMethodId): array
    {
        /** @var StripePaymentMethod $pm */
        $pm = $this->sdk($secretKey)->paymentMethods->retrieve($paymentMethodId);

        $card = null;
        if ($pm->card !== null) {
            $card = [
                'brand' => $pm->card->brand !== null ? (string) $pm->card->brand : null,
                'last4' => $pm->card->last4 !== null ? (string) $pm->card->last4 : null,
                'exp_month' => $pm->card->exp_month !== null ? (int) $pm->card->exp_month : null,
                'exp_year' => $pm->card->exp_year !== null ? (int) $pm->card->exp_year : null,
            ];
        }

        return [
            'id' => (string) $pm->id,
            'type' => $pm->type !== null ? (string) $pm->type : null,
            'card' => $card,
        ];
    }

    /**
     * Best-effort remote detach — callers swallow ApiErrorException.
     *
     * @throws ApiErrorException
     */
    public function detachPaymentMethod(string $secretKey, string $paymentMethodId): void
    {
        $this->sdk($secretKey)->paymentMethods->detach($paymentMethodId);
    }

    /**
     * @param  array{
     *     amount: int,
     *     currency: string,
     *     customer?: string|null,
     *     setup_future_usage?: string|null,
     *     metadata?: array<string, string>
     * }  $params
     * @return array{id: string, client_secret: string|null, status: string}
     *
     * @throws ApiErrorException
     */
    public function createPaymentIntent(string $secretKey, array $params): array
    {
        $payload = [
            'amount' => $params['amount'],
            'currency' => strtolower($params['currency']),
            'payment_method_types' => ['card'],
            'metadata' => $params['metadata'] ?? [],
        ];

        if (! empty($params['customer'])) {
            $payload['customer'] = $params['customer'];
        }

        if (! empty($params['setup_future_usage'])) {
            $payload['setup_future_usage'] = $params['setup_future_usage'];
        }

        /** @var PaymentIntent $intent */
        $intent = $this->sdk($secretKey)->paymentIntents->create($payload);

        return [
            'id' => (string) $intent->id,
            'client_secret' => $intent->client_secret !== null ? (string) $intent->client_secret : null,
            'status' => (string) $intent->status,
        ];
    }

    /**
     * @return array{id: string, client_secret: string|null, status: string}
     *
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent(string $secretKey, string $paymentIntentId): array
    {
        /** @var PaymentIntent $intent */
        $intent = $this->sdk($secretKey)->paymentIntents->retrieve($paymentIntentId);

        return [
            'id' => (string) $intent->id,
            'client_secret' => $intent->client_secret !== null ? (string) $intent->client_secret : null,
            'status' => (string) $intent->status,
        ];
    }

    /**
     * Best-effort remote cancel — callers swallow ApiErrorException.
     *
     * @throws ApiErrorException
     */
    public function cancelPaymentIntent(string $secretKey, string $paymentIntentId): void
    {
        $this->sdk($secretKey)->paymentIntents->cancel($paymentIntentId);
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
