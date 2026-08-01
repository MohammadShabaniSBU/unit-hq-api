<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeliveryWebhookEvent;
use App\Models\CommsWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\SystemEvent;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public inbound delivery webhook receiver. Authenticated by the per-account
 * URL token. Persists each parsed DeliveryEvent under
 * (communication_account_id, provider_event_id) then dispatches processing —
 * Stripe-shaped idempotency.
 */
class DeliveryWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        string $webhookUrlToken,
        ProviderRegistry $registry,
    ): JsonResponse {
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

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $adapter = $registry->make($account->channel, $account->provider, $credentials);

        if (! $adapter instanceof ReportsDeliveryEvents) {
            return response()->json(['message' => 'ok']);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $events = $adapter->parseDeliveryEvents($payload);

        foreach ($events as $event) {
            $row = CommsWebhookEvent::query()
                ->where('communication_account_id', $account->id)
                ->where('provider_event_id', $event->providerEventId)
                ->first();

            if ($row === null) {
                try {
                    $row = CommsWebhookEvent::query()->create([
                        'communication_account_id' => $account->id,
                        'provider_event_id' => $event->providerEventId,
                        'payload' => $payload,
                        'processing_status' => 'pending',
                        'received_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $row = CommsWebhookEvent::query()
                        ->where('communication_account_id', $account->id)
                        ->where('provider_event_id', $event->providerEventId)
                        ->first();
                }
            }

            if ($row !== null && $row->processing_status === 'pending') {
                ProcessDeliveryWebhookEvent::dispatch($row->id);
            }
        }

        return response()->json(['message' => 'ok']);
    }
}
