<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ChannelDeliveryFailed;
use App\Support\Communications\SuppressionWriter;

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
    }
}
