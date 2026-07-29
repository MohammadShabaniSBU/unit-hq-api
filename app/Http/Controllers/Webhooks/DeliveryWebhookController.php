<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeliveryWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\SystemEvent;
use App\Support\Communications\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public inbound delivery webhook receiver. Authenticated by the per-account
 * URL token. Ignores archived sites; processing happens in the queued job.
 */
class DeliveryWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, string $webhookUrlToken): JsonResponse
    {
        try {
            $providerEnum = Provider::from($provider);
        } catch (\ValueError) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        $account = CommunicationAccount::query()
            ->where('webhook_url_token', $webhookUrlToken)
            ->where('provider', $providerEnum)
            ->with('site')
            ->first();

        if ($account === null) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if ($account->site !== null && $account->site->isArchived()) {
            return response()->json(['message' => 'ok']);
        }

        SystemEvent::record('webhook.'.$providerEnum->value.'.received', $account, [
            'account_id' => $account->id,
            'provider' => $providerEnum->value,
            'channel' => $account->channel->value,
        ]);

        ProcessDeliveryWebhookEvent::dispatch($account->id, $request->all());

        return response()->json(['message' => 'ok']);
    }
}
