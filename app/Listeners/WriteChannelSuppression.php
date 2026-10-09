<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ChannelDeliveryFailed;
use App\Support\Communications\Channel;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Communications\SuppressionWriter;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;

/**
 * S10-03 sole consumer of ChannelDeliveryFailed — writes address-keyed suppressions.
 */
class WriteChannelSuppression
{
    public function handle(ChannelDeliveryFailed $event): void
    {
        SuppressionWriter::fromDeliveryFailure(
            $event->channel,
            $event->address,
            $event->status,
            $event->isPermanent,
            $event->messageId,
        );

        if (
            $event->isPermanent
            && $event->status === DeliveryStatus::Spam
            && $event->channel === Channel::Email
        ) {
            ConsentWriter::withdrawAllListsForAddress(
                Channel::Email,
                $event->address,
                ConsentSource::Complaint,
                ['message_id' => $event->messageId],
            );
        }
    }
}
