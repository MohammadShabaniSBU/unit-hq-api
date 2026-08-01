<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StripeWebhookEvent;
use App\Support\Payments\PaymentMethods;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles verified Stripe events. S06-01 handles setup_intent.succeeded
 * (local payment_methods rows). PaymentIntent → ledger is S06-03.
 */
class ProcessStripeWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $stripeWebhookEventId) {}

    public function handle(): void
    {
        $event = StripeWebhookEvent::query()->find($this->stripeWebhookEventId);

        if ($event === null || $event->processing_status !== 'pending') {
            return;
        }

        match ($event->event_type) {
            'setup_intent.succeeded' => $this->handleSetupIntentSucceeded($event),
            // TODO S06-03: payment_intent.succeeded / payment_failed / charge.refunded
            default => null,
        };

        $event->update([
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function handleSetupIntentSucceeded(StripeWebhookEvent $event): void
    {
        $account = $event->paymentProviderAccount;
        if ($account === null) {
            Log::warning('stripe.setup_intent.missing_account', [
                'stripe_webhook_event_id' => $event->id,
            ]);

            return;
        }

        $payload = $event->payload;
        $setupIntent = is_array($payload['data']['object'] ?? null)
            ? $payload['data']['object']
            : null;

        if ($setupIntent === null) {
            Log::warning('stripe.setup_intent.missing_object', [
                'stripe_webhook_event_id' => $event->id,
            ]);

            return;
        }

        PaymentMethods::recordFromSetupIntent($account, $setupIntent);
    }
}
