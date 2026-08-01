<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommsWebhookEvent;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\DeliveryEventApplier;
use App\Support\Communications\ProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reconciles a persisted provider delivery event onto Message (canonical),
 * with legacy OfferDelivery update and playbook step back-fill.
 */
class ProcessDeliveryWebhookEvent implements ShouldQueue
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

        if (! $adapter instanceof ReportsDeliveryEvents) {
            $row->update([
                'processing_status' => 'failed',
                'processed_at' => now(),
            ]);

            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($row->payload) ? $row->payload : [];
        $events = $adapter->parseDeliveryEvents($payload);

        $event = null;
        foreach ($events as $candidate) {
            if ($candidate->providerEventId === $row->provider_event_id) {
                $event = $candidate;
                break;
            }
        }

        if ($event === null) {
            $row->update([
                'processing_status' => 'failed',
                'processed_at' => now(),
            ]);

            return;
        }

        $message = DeliveryEventApplier::apply($account->provider, $event);

        if ($message === null) {
            DeliveryEventApplier::recordUnmatched(
                $account->provider,
                $event,
                (int) $account->id,
            );

            $row->update([
                'processing_status' => 'unmatched',
                'processed_at' => now(),
            ]);

            return;
        }

        $row->update([
            'processing_status' => 'processed',
            'message_id' => $message->id,
            'processed_at' => now(),
        ]);
    }
}
