<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunicationAccount;
use App\Models\Interaction;
use App\Models\OfferDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stub: reconciles a Brevo delivery event against the row that sent it
 * (matched by provider message id). Full status-mapping / Interaction
 * timeline writes are follow-up work — this keeps the webhook receiver
 * fast while structuring where that logic will live.
 */
class ProcessBrevoWebhookEvent implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $accountId,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $account = CommunicationAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        $messageId = $this->payload['message-id'] ?? $this->payload['messageId'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return;
        }

        OfferDelivery::query()->where('message_id', $messageId)->first();
        Interaction::query()->where('message_id', $messageId)->first();

        // TODO: map $this->payload['event'] (delivered/hardBounce/…) onto the
        // matched OfferDelivery.delivery_status / Interaction, once the
        // Brevo event vocabulary is finalised.
    }
}
