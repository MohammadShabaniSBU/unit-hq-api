<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EsignEnvelope;
use App\Models\EsignWebhookEvent;
use App\Models\SystemEvent;
use App\Support\ESign\EnvelopeOrchestrator;
use App\Support\ESign\ESignProviderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Parse webhook, apply envelope side effects, mark processed (S14-03).
 */
class ProcessEsignWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $esignWebhookEventId) {}

    public function handle(ESignProviderRegistry $registry, EnvelopeOrchestrator $orchestrator): void
    {
        $event = EsignWebhookEvent::query()->find($this->esignWebhookEventId);

        if ($event === null || $event->processing_status !== 'pending') {
            return;
        }

        $account = $event->esignProviderAccount;

        if ($account === null) {
            $event->processing_status = 'failed';
            $event->processed_at = now();
            $event->save();

            return;
        }

        try {
            $adapter = $registry->forAccount($account);
            /** @var array<string, mixed> $payload */
            $payload = $event->payload;
            $parsed = $adapter->parseWebhook($payload);

            if (! $parsed->isKnown()) {
                SystemEvent::record('webhook.esign.unknown_type', $account, [
                    'account_id' => $account->id,
                    'provider_event_id' => $event->provider_event_id,
                    'type' => $parsed->type,
                ]);
            } elseif ($parsed->envelopeRef !== '') {
                $envelope = EsignEnvelope::query()
                    ->where('esign_provider_account_id', $account->id)
                    ->where('provider_envelope_ref', $parsed->envelopeRef)
                    ->first();

                if ($envelope !== null) {
                    $orchestrator->applyEvent($envelope, $parsed);
                }
            }

            $event->processing_status = 'processed';
            $event->processed_at = now();
            $event->save();
        } catch (\Throwable $e) {
            $event->processing_status = 'failed';
            $event->processed_at = now();
            $event->save();

            SystemEvent::record('webhook.esign.failed', $account, [
                'account_id' => $account->id,
                'provider_event_id' => $event->provider_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
