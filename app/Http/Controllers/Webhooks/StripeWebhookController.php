<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\PaymentProviderAccount;
use App\Models\StripeWebhookEvent;
use App\Models\SystemEvent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Public inbound Stripe webhook receiver — one route per provider account via
 * account_token. Verifies Stripe-Signature against that account's own
 * webhook_secret. Payments are only ever confirmed from here, never
 * optimistically from the client (invariant 11).
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, string $accountToken): JsonResponse
    {
        $account = PaymentProviderAccount::query()
            ->where('account_token', $accountToken)
            ->with('legalEntity')
            ->first();

        if ($account === null || $account->legalEntity === null) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if ($account->legalEntity->isArchived() || ! $account->is_active) {
            // Archived entities / inactive accounts: ack and ignore.
            return response()->json(['message' => 'ok']);
        }

        try {
            $webhookSecret = $account->webhook_secret;
        } catch (DecryptException) {
            return response()->json(['message' => 'Webhook secret unreadable.'], 500);
        }

        if ($webhookSecret === null) {
            return response()->json(['message' => 'Webhook not configured.'], 404);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret,
            );
        } catch (SignatureVerificationException) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\UnexpectedValueException) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $existing = StripeWebhookEvent::query()
            ->where('payment_provider_account_id', $account->id)
            ->where('stripe_event_id', $event->id)
            ->first();

        if ($existing === null) {
            $existing = StripeWebhookEvent::query()->create([
                'payment_provider_account_id' => $account->id,
                'stripe_event_id' => $event->id,
                'event_type' => $event->type,
                'payload' => $event->toArray(),
                'processing_status' => 'pending',
                'received_at' => now(),
            ]);
        }

        SystemEvent::record('webhook.stripe.received', $account->legalEntity, [
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
            'payment_provider_account_id' => $account->id,
        ]);

        ProcessStripeWebhookEvent::dispatch($existing->id);

        return response()->json(['message' => 'ok']);
    }
}
