<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEsignWebhookEvent;
use App\Models\EsignProviderAccount;
use App\Models\EsignWebhookEvent;
use App\Models\SystemEvent;
use App\Support\ESign\ESignEvent;
use App\Support\ESign\ESignProviderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public e-sign webhook receiver. Authenticated by per-account webhook_token.
 * Inactive accounts ack-and-ignore (S06 posture).
 */
class EsignWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $webhookToken,
        ESignProviderRegistry $registry,
    ): JsonResponse {
        $account = EsignProviderAccount::query()
            ->where('webhook_token', $webhookToken)
            ->first();

        if ($account === null) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if (! $account->is_active) {
            return response()->json(['message' => 'ok']);
        }

        SystemEvent::record('webhook.esign.received', $account, [
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
                SystemEvent::record('webhook.esign.unknown_type', $account, [
                    'account_id' => $account->id,
                    'provider_event_id' => $providerEventId,
                    'type' => $parsed->type !== ESignEvent::TYPE_UNKNOWN
                        ? $parsed->type
                        : (string) ($payload['webhook_type'] ?? $payload['type'] ?? 'unknown'),
                ]);
            }
        } catch (\Throwable) {
            $providerEventId = 'raw:'.md5(json_encode($payload) ?: uniqid('esign', true));
            SystemEvent::record('webhook.esign.unknown_type', $account, [
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
        $row = EsignWebhookEvent::query()
            ->where('esign_provider_account_id', $accountId)
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($row === null) {
            try {
                $row = EsignWebhookEvent::query()->create([
                    'esign_provider_account_id' => $accountId,
                    'provider_event_id' => $providerEventId,
                    'payload' => $payload,
                    'processing_status' => 'pending',
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $row = EsignWebhookEvent::query()
                    ->where('esign_provider_account_id', $accountId)
                    ->where('provider_event_id', $providerEventId)
                    ->first();
            }
        }

        if ($row !== null && $row->processing_status === 'pending') {
            ProcessEsignWebhookEvent::dispatch($row->id);
        }
    }
}
