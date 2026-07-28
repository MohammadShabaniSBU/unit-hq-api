<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\SiteStripeSetting;
use App\Models\StripeWebhookEvent;
use App\Models\SystemEvent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Public inbound Stripe webhook receiver — one route per site via
 * webhook_route_token. Verifies Stripe-Signature against that site's own
 * webhook_secret (per-site direct charges, no Connect). Payments are only
 * ever confirmed from here, never optimistically from the client
 * (05-billing-ledger.md / 09-conventions-and-invariants.md #11).
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, string $webhookRouteToken): JsonResponse
    {
        $setting = SiteStripeSetting::query()
            ->where('webhook_route_token', $webhookRouteToken)
            ->with('site')
            ->first();

        if ($setting === null || $setting->site === null) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if ($setting->site->isArchived()) {
            // Archived sites: inbound webhooks are ignored, not processed.
            return response()->json(['message' => 'ok']);
        }

        try {
            $webhookSecret = $setting->webhook_secret;
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
        } catch (SignatureVerificationException $e) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $existing = StripeWebhookEvent::query()->where('stripe_event_id', $event->id)->first();

        if ($existing === null) {
            $existing = StripeWebhookEvent::query()->create([
                'site_id' => $setting->site_id,
                'stripe_event_id' => $event->id,
                'event_type' => $event->type,
                'payload' => $event->toArray(),
                'processing_status' => 'pending',
                'received_at' => now(),
            ]);
        }

        SystemEvent::record('webhook.stripe.received', $setting->site, [
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        ProcessStripeWebhookEvent::dispatch($existing->id);

        return response()->json(['message' => 'ok']);
    }
}
