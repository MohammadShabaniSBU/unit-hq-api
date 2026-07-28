<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBrevoWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\SystemEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public inbound Brevo webhook receiver. Verifies the URL-embedded token,
 * ignores events for archived sites, and acks fast — real processing (offer
 * delivery status updates, interaction logging) happens in the queued job.
 */
class BrevoWebhookController extends Controller
{
    public function __invoke(Request $request, string $webhookUrlToken): JsonResponse
    {
        $account = CommunicationAccount::query()
            ->where('webhook_url_token', $webhookUrlToken)
            ->with('site')
            ->first();

        if ($account === null) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if ($account->site !== null && $account->site->isArchived()) {
            // Archived sites: inbound webhooks are ignored, not processed.
            return response()->json(['message' => 'ok']);
        }

        SystemEvent::record('webhook.brevo.received', $account, [
            'account_id' => $account->id,
            'provider_type' => $account->provider_type->value,
        ]);

        ProcessBrevoWebhookEvent::dispatch($account->id, $request->all());

        return response()->json(['message' => 'ok']);
    }
}
