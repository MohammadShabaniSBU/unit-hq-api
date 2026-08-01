<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommsWebhookEvent;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\InboundReceiptApplier;
use App\Support\Communications\ProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Persists inbound content from a stored webhook payload onto Message / triage.
 */
class ProcessInboundWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $commsWebhookEventId,
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        $row = CommsWebhookEvent::query()
            ->with('communicationAccount')
            ->find($this->commsWebhookEventId);

        if ($row === null || $row->processing_status !== 'pending') {
            return;
        }

        $account = $row->communicationAccount;
        if ($account === null) {
            $row->update([
                'processing_status' => 'failed',
                'processed_at' => now(),
            ]);

            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $adapter = $registry->make($account->channel, $account->provider, $credentials);

        if (! $adapter instanceof ReceivesInbound) {
            $row->update([
                'processing_status' => 'failed',
                'processed_at' => now(),
            ]);

            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($row->payload) ? $row->payload : [];
        $inbound = $adapter->parseInbound($payload);

        if ($inbound === null || $inbound->providerEventId !== $row->provider_event_id) {
            $row->update([
                'processing_status' => 'failed',
                'processed_at' => now(),
            ]);

            return;
        }

        $result = InboundReceiptApplier::apply(
            $account->provider,
            (int) $account->id,
            $inbound,
        );

        if ($result['outcome'] === 'message' || $result['outcome'] === 'duplicate') {
            $row->update([
                'processing_status' => 'processed',
                'message_id' => $result['message']?->id,
                'processed_at' => now(),
            ]);

            return;
        }

        // Triage: content parked; webhook is done.
        $row->update([
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }
}
