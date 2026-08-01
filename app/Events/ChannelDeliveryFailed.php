<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\Communications\Channel;
use App\Support\Communications\Results\DeliveryStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fact emitted when a bounce or spam complaint lands on a message.
 * S10-03 (consent & suppression) is the sole consumer — no suppression here.
 */
class ChannelDeliveryFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $messageId,
        public readonly Channel $channel,
        public readonly string $address,
        public readonly bool $isPermanent,
        public readonly DeliveryStatus $status,
        public readonly ?string $reason = null,
    ) {}
}
