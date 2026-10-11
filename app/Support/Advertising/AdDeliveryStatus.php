<?php

declare(strict_types=1);

namespace App\Support\Advertising;

enum AdDeliveryStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Claimed,
            self::Claimed => in_array($next, [self::Sent, self::Skipped, self::Failed], true),
            self::Failed => $next === self::Pending,
            self::Sent, self::Skipped => false,
        };
    }
}
