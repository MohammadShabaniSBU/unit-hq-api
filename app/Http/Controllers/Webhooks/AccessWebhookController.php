<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAccessWebhookEvent;
use App\Models\AccessProviderAccount;
use App\Models\AccessWebhookEvent;
use App\Models\SystemEvent;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\AccessWebhookPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public access webhook receiver. Authenticated by per-account webhook_token.
 * Inactive accounts ack-and-ignore (S06 posture).
 */
class AccessWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $webhookToken,
        AccessProviderRegistry $registry,
    ): JsonResponse {
        $account = AccessProviderAccount::query()
            ->where('webhook_token', $webhookToken)
            ->first();

        if ($account === null) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if (! $account->is_active) {
            return response()->json(['message' => 'ok']);
        }

        SystemEvent::record('webhook.access.received', $account, [
            'account_id' => $account->id,
            'provider' => $account->provider->value,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        try {
            $adapter = $registry->forAccount($account);
            $parsed = $adapter->parseWebhook($payload);
            $providerEventId = $parsed->providerEventId;

            if (! $parsed->isKnown()) {
                SystemEvent::record('webhook.access.unknown_type', $account, [
                    'account_id' => $account->id,
                    'provider_event_id' => $providerEventId,
                    'type' => $parsed->eventType !== AccessWebhookPayload::TYPE_UNKNOWN
                        ? $parsed->eventType
                        : (string) ($payload['type'] ?? $payload['event_type'] ?? 'unknown'),
                ]);
            }
        } catch (\Throwable) {
            $providerEventId = 'raw:'.md5(json_encode($payload) ?: uniqid('access', true));
            SystemEvent::record('webhook.access.unknown_type', $account, [
                'account_id' => $account->id,
                'provider_event_id' => $providerEventId,
                'type' => 'parse_failed',
            ]);
        }

        $this->persistAndDispatch($account->id, $providerEventId, $payload);

        return response()->json(['message' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistAndDispatch(int $accountId, string $providerEventId, array $payload): void
    {
        $row = AccessWebhookEvent::query()
            ->where('access_provider_account_id', $accountId)
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($row === null) {
            try {
                $row = AccessWebhookEvent::query()->create([
                    'access_provider_account_id' => $accountId,
                    'provider_event_id' => $providerEventId,
                    'payload' => $payload,
                    'processing_status' => 'pending',
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $row = AccessWebhookEvent::query()
                    ->where('access_provider_account_id', $accountId)
                    ->where('provider_event_id', $providerEventId)
                    ->first();
            }
        }

        if ($row !== null && $row->processing_status === 'pending') {
            ProcessAccessWebhookEvent::dispatch($row->id);
        }
    }
}
