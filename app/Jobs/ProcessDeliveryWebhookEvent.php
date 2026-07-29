<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunicationAccount;
use App\Models\Interaction;
use App\Models\OfferDelivery;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\ProviderRegistry;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reconciles a provider delivery event against OfferDelivery / Interaction
 * rows matched by provider_message_id.
 */
class ProcessDeliveryWebhookEvent implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $accountId,
        public readonly array $payload,
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        $account = CommunicationAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $adapter = $registry->make($account->channel, $account->provider, $credentials);

        if (! $adapter instanceof ReportsDeliveryEvents) {
            return;
        }

        foreach ($adapter->parseDeliveryEvents($this->payload) as $event) {
            $delivery = OfferDelivery::query()
                ->where('provider_message_id', $event->providerMessageId)
                ->first();

            if ($delivery !== null) {
                $delivery->delivery_status = $event->status->value;

                if ($event->status === DeliveryStatus::Delivered && $delivery->delivered_at === null) {
                    $delivery->delivered_at = $event->occurredAt?->toMutable() ?? now();
                }

                $delivery->save();
            }

            Interaction::query()
                ->where('provider_message_id', $event->providerMessageId)
                ->first();
        }
    }
}
