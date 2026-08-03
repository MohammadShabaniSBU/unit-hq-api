<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Injectors;

use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\PaymentProviderAccount;
use App\Models\StripeWebhookEvent;
use App\Support\Payments\MinorUnits;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Support\Str;

/**
 * Fabricates Stripe-shaped events and enters at ProcessStripeWebhookEvent
 * (sanctioned bypass — seeded fake accounts have no webhook_secret).
 */
final class StripeInjector
{
    public function __construct(private readonly DemoWorld $world) {}

    /**
     * @param  array<string, string>  $metadata
     */
    public function paymentSucceeded(
        string $paymentIntentId,
        string $amount,
        string $currency = 'EUR',
        array $metadata = [],
        ?PaymentProviderAccount $account = null,
    ): StripeWebhookEvent {
        $account ??= $this->world->stripeAccount();
        $eventId = 'evt_demo_'.Str::lower(Str::random(12));

        $payload = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => MinorUnits::toMinor($amount, $currency),
                    'currency' => strtolower($currency),
                    'status' => 'succeeded',
                    'metadata' => $metadata,
                ],
            ],
        ];

        return $this->dispatch($account, $eventId, 'payment_intent.succeeded', $payload);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function paymentFailed(
        string $paymentIntentId,
        string $code = 'card_declined',
        string $amount = '0.00',
        string $currency = 'EUR',
        array $metadata = [],
        ?PaymentProviderAccount $account = null,
    ): StripeWebhookEvent {
        $account ??= $this->world->stripeAccount();
        $eventId = 'evt_demo_'.Str::lower(Str::random(12));

        $payload = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => MinorUnits::toMinor($amount, $currency),
                    'currency' => strtolower($currency),
                    'status' => 'requires_payment_method',
                    'metadata' => $metadata,
                    'last_payment_error' => [
                        'code' => $code,
                        'decline_code' => $code,
                        'message' => "Simulated failure: {$code}",
                    ],
                ],
            ],
        ];

        return $this->dispatch($account, $eventId, 'payment_intent.payment_failed', $payload);
    }

    /**
     * @param  array{brand?: string, last4?: string}|null  $card
     */
    public function setupSucceeded(
        int $contactId,
        string $paymentMethodId,
        ?array $card = null,
        ?PaymentProviderAccount $account = null,
    ): StripeWebhookEvent {
        $account ??= $this->world->stripeAccount();
        $eventId = 'evt_demo_'.Str::lower(Str::random(12));
        $card ??= ['brand' => 'visa', 'last4' => '4242'];

        $payload = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'setup_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'seti_demo_'.Str::lower(Str::random(8)),
                    'object' => 'setup_intent',
                    'payment_method' => $paymentMethodId,
                    'metadata' => [
                        'contact_id' => (string) $contactId,
                        'payment_provider_account_id' => (string) $account->id,
                    ],
                    // Card details unused by processor (retrieves via StripeClient);
                    // present for payload realism.
                    'card' => $card,
                ],
            ],
        ];

        return $this->dispatch($account, $eventId, 'setup_intent.succeeded', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(
        PaymentProviderAccount $account,
        string $eventId,
        string $eventType,
        array $payload,
    ): StripeWebhookEvent {
        $event = StripeWebhookEvent::query()->create([
            'payment_provider_account_id' => $account->id,
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        (new ProcessStripeWebhookEvent($event->id))->handle();

        return $event->fresh() ?? $event;
    }
}
