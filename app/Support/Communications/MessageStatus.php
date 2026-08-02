<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Read = 'read';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Failed = 'failed';
    case Spam = 'spam';
    case Received = 'received';

    /**
     * Lattice rank aligned with DeliveryStatus::rank() for outbound states.
     * Received (inbound) is outside the delivery lattice.
     * Opened (email) and Read (WhatsApp) share rank 40 — they never compete.
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::Queued => 10,
            self::Sent => 20,
            self::Delivered => 30,
            self::Opened, self::Read => 40,
            self::Clicked => 50,
            self::Failed, self::Bounced, self::Spam => 100,
            self::Received => null,
        };
    }
}
