<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeliveryWebhookEvent;
use App\Jobs\ProcessInboundWebhookEvent;
use App\Models\CommsWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\SystemEvent;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public provider webhook receiver. Authenticated by the per-account URL token.
 * Splits delivery-status callbacks vs inbound content by adapter parse results —
 * Stripe-shaped idempotency on (communication_account_id, provider_event_id).
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

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        if ($adapter instanceof ReportsDeliveryEvents) {
            foreach ($adapter->parseDeliveryEvents($payload) as $event) {
                $this->persistAndDispatch(
                    $account->id,
                    $event->providerEventId,
                    $payload,
                    fn (int $id) => ProcessDeliveryWebhookEvent::dispatch($id),
                );
            }
        }

        if ($adapter instanceof ReceivesInbound) {
            $inbound = $adapter->parseInbound($payload);
            if ($inbound !== null) {
                $this->persistAndDispatch(
                    $account->id,
                    $inbound->providerEventId,
                    $payload,
                    fn (int $id) => ProcessInboundWebhookEvent::dispatch($id),
                );
            }
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(int): void  $dispatch
     */
    private function persistAndDispatch(
        int $accountId,
        string $providerEventId,
        array $payload,
        callable $dispatch,
    ): void {
        $row = CommsWebhookEvent::query()
            ->where('communication_account_id', $accountId)
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($row === null) {
            try {
                $row = CommsWebhookEvent::query()->create([
                    'communication_account_id' => $accountId,
                    'provider_event_id' => $providerEventId,
                    'payload' => $payload,
                    'processing_status' => 'pending',
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $row = CommsWebhookEvent::query()
                    ->where('communication_account_id', $accountId)
                    ->where('provider_event_id', $providerEventId)
                    ->first();
            }
        }

        if ($row !== null && $row->processing_status === 'pending') {
            $dispatch($row->id);
        }
    }
}
