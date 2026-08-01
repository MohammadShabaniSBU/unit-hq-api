<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AutopayAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Models\AutopayAttempt;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\StripeWebhookEvent;
use App\Models\SystemEvent;
use App\Support\Billing\BillingMath;
use App\Support\Billing\PaymentAllocator;
use App\Support\Payments\MinorUnits;
use App\Support\Payments\PaymentMethods;
use App\Support\RecordsActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles verified Stripe events into the append-only ledger (rail A).
 * PI-id as payments.idempotency_key enforces invariant 3 under replay.
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
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            default => $this->handleUnknown($event),
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

        $setupIntent = $this->objectFromPayload($event);
        if ($setupIntent === null) {
            Log::warning('stripe.setup_intent.missing_object', [
                'stripe_webhook_event_id' => $event->id,
            ]);

            return;
        }

        PaymentMethods::recordFromSetupIntent($account, $setupIntent);
    }

    private function handlePaymentIntentSucceeded(StripeWebhookEvent $event): void
    {
        $pi = $this->objectFromPayload($event);
        if ($pi === null) {
            Log::warning('stripe.payment_intent.missing_object', [
                'stripe_webhook_event_id' => $event->id,
            ]);

            return;
        }

        $piId = self::stringOrNull($pi['id'] ?? null);
        if ($piId === null) {
            return;
        }

        $metadata = is_array($pi['metadata'] ?? null) ? $pi['metadata'] : [];
        $paymentRequestId = isset($metadata['payment_request_id'])
            ? (int) $metadata['payment_request_id']
            : 0;
        $autopayAttemptId = isset($metadata['autopay_attempt_id'])
            ? (int) $metadata['autopay_attempt_id']
            : 0;

        $paymentRequest = $paymentRequestId > 0
            ? PaymentRequest::query()->find($paymentRequestId)
            : null;
        $attempt = $autopayAttemptId > 0
            ? AutopayAttempt::query()->find($autopayAttemptId)
            : null;

        if ($paymentRequest === null && $attempt === null) {
            SystemEvent::record('stripe.orphan_intent', $event->paymentProviderAccount, [
                'stripe_webhook_event_id' => $event->id,
                'stripe_payment_intent_id' => $piId,
                'metadata' => $metadata,
            ]);

            return;
        }

        $currency = strtoupper(self::stringOrNull($pi['currency'] ?? null) ?? 'EUR');
        $amountMinor = (int) ($pi['amount'] ?? 0);
        $amount = MinorUnits::fromMinor($amountMinor, $currency);

        $contract = $paymentRequest?->contract ?? $attempt?->contract;
        if ($contract === null) {
            SystemEvent::record('stripe.orphan_intent', $event->paymentProviderAccount, [
                'stripe_webhook_event_id' => $event->id,
                'stripe_payment_intent_id' => $piId,
                'reason' => 'missing_contract',
            ]);

            return;
        }

        $chargeIds = $paymentRequest !== null
            ? array_map('intval', $paymentRequest->charge_ids ?? [])
            : array_map('intval', $attempt->charge_ids ?? []);

        // Resolve payment outside the ledger TX: a unique-key collision aborts
        // the PG transaction, so create+catch cannot sit inside DB::transaction.
        [$payment, $isNew] = $this->findOrCreateStripePayment(
            contractId: (int) $contract->id,
            amount: $amount,
            currency: $currency,
            piId: $piId,
        );

        DB::transaction(function () use (
            $event,
            $piId,
            $contract,
            $chargeIds,
            $paymentRequest,
            $attempt,
            $payment,
            $isNew,
        ): void {
            // Skip if a prior partial run already allocated (PI-id unique caught).
            if ($payment->allocations()->doesntExist()) {
                PaymentAllocator::allocateTargetedThenOldest($contract, $payment, $chargeIds);
            }

            if ($paymentRequest !== null
                && $paymentRequest->status !== PaymentRequestStatus::Paid) {
                $paymentRequest->update([
                    'status' => PaymentRequestStatus::Paid,
                    'paid_payment_id' => $payment->id,
                    'stripe_payment_intent_id' => $piId,
                ]);
            }

            if ($attempt !== null
                && $attempt->status !== AutopayAttemptStatus::Succeeded) {
                $attempt->update([
                    'status' => AutopayAttemptStatus::Succeeded,
                    'stripe_payment_intent_id' => $piId,
                    'resolved_at' => now(),
                    'failure_code' => null,
                    'decline_code' => null,
                    'failure_message' => null,
                ]);
            }

            $event->update(['payment_id' => $payment->id]);

            if ($isNew) {
                $allocations = $payment->allocations()->get();
                RecordsActivity::core('payment.recorded', $payment, [
                    'amount' => BillingMath::round2((string) $payment->amount),
                    'currency' => (string) $payment->currency,
                    'method' => PaymentMethod::StripeCard->value,
                    'rail' => 'stripe',
                    'stripe_payment_intent_id' => $piId,
                    'contract_id' => $contract->id,
                    'allocation_count' => $allocations->count(),
                    'allocated_total' => BillingMath::round2((string) $allocations->sum('amount')),
                ], anonymous: true);
            }
        });
    }

    private function handlePaymentIntentFailed(StripeWebhookEvent $event): void
    {
        $pi = $this->objectFromPayload($event);
        if ($pi === null) {
            return;
        }

        $piId = self::stringOrNull($pi['id'] ?? null);
        $metadata = is_array($pi['metadata'] ?? null) ? $pi['metadata'] : [];
        $paymentRequestId = isset($metadata['payment_request_id'])
            ? (int) $metadata['payment_request_id']
            : 0;
        $autopayAttemptId = isset($metadata['autopay_attempt_id'])
            ? (int) $metadata['autopay_attempt_id']
            : 0;

        $lastError = is_array($pi['last_payment_error'] ?? null) ? $pi['last_payment_error'] : [];
        $failureCode = self::stringOrNull($lastError['code'] ?? null);
        $declineCode = self::stringOrNull($lastError['decline_code'] ?? null);
        $failureMessage = self::stringOrNull($lastError['message'] ?? null);

        // Payment request stays pending so the public page can retry.
        if ($paymentRequestId > 0) {
            $request = PaymentRequest::query()->find($paymentRequestId);
            if ($request !== null
                && $request->status === PaymentRequestStatus::Processing) {
                $request->update(['status' => PaymentRequestStatus::Pending]);
            }
        }

        if ($autopayAttemptId > 0) {
            $attempt = AutopayAttempt::query()->find($autopayAttemptId);
            if ($attempt !== null && $attempt->status === AutopayAttemptStatus::Pending) {
                $attempt->update([
                    'status' => AutopayAttemptStatus::Failed,
                    'stripe_payment_intent_id' => $piId,
                    'failure_code' => $failureCode,
                    'decline_code' => $declineCode,
                    'failure_message' => $failureMessage,
                    'resolved_at' => now(),
                ]);
            }
        }

        SystemEvent::record('stripe.payment_intent.failed', $event->paymentProviderAccount, [
            'stripe_webhook_event_id' => $event->id,
            'stripe_payment_intent_id' => $piId,
            'payment_request_id' => $paymentRequestId > 0 ? $paymentRequestId : null,
            'autopay_attempt_id' => $autopayAttemptId > 0 ? $autopayAttemptId : null,
            'failure_code' => $failureCode,
            'decline_code' => $declineCode,
        ]);
    }

    private function handleChargeRefunded(StripeWebhookEvent $event): void
    {
        $charge = $this->objectFromPayload($event);
        $chargeId = self::stringOrNull($charge['id'] ?? null);
        $piId = self::stringOrNull($charge['payment_intent'] ?? null);

        SystemEvent::record('stripe.refund_external', $event->paymentProviderAccount, [
            'stripe_webhook_event_id' => $event->id,
            'stripe_charge_id' => $chargeId,
            'stripe_payment_intent_id' => $piId,
            'message' => 'Refund issued in Stripe dashboard — reconcile manually via reversal.',
        ]);
    }

    private function handleUnknown(StripeWebhookEvent $event): void
    {
        SystemEvent::record('stripe.webhook.unknown', $event->paymentProviderAccount, [
            'stripe_webhook_event_id' => $event->id,
            'event_type' => $event->event_type,
            'stripe_event_id' => $event->stripe_event_id,
        ]);
    }

    /**
     * Create payment with idempotency_key = PI id (unique). Duplicate key →
     * already-processed ack — the unique column makes double-writes impossible.
     *
     * @return array{0: Payment, 1: bool}  [payment, isNew]
     */
    private function findOrCreateStripePayment(
        int $contractId,
        string $amount,
        string $currency,
        string $piId,
    ): array {
        $existing = Payment::query()->where('idempotency_key', $piId)->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $payment = Payment::query()->create([
                'contract_id' => $contractId,
                'amount' => $amount,
                'currency' => $currency,
                'method' => PaymentMethod::StripeCard,
                'received_on' => now()->toDateString(),
                'reference' => null,
                'stripe_payment_intent_id' => $piId,
                // Invariant 3 / rail A: PI id is the idempotency key.
                'idempotency_key' => $piId,
                'reversal_of_payment_id' => null,
            ]);

            return [$payment, true];
        } catch (UniqueConstraintViolationException) {
            return [
                Payment::query()->where('idempotency_key', $piId)->firstOrFail(),
                false,
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function objectFromPayload(StripeWebhookEvent $event): ?array
    {
        $payload = $event->payload;
        $object = $payload['data']['object'] ?? null;

        return is_array($object) ? $object : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
