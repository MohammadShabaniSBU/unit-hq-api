<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StripeWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stub: reconciles a Stripe event against the ledger (the ledger stays the
 * system of record — this job maps confirmed payment_intent/charge events
 * onto Payment rows). Real reconciliation is out of this phase's scope;
 * this keeps the webhook receiver fast and structures where it will live.
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

        // TODO: map $event->event_type (payment_intent.succeeded, …) onto a
        // Payment row via payment_provider_account_id, then mark processed/failed.
        $event->update(['processed_at' => now()]);
    }
}
