<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case NoticeGiven = 'notice_given';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ended, self::Cancelled => true,
            default => false,
        };
    }

    public function isInForce(): bool
    {
        return match ($this) {
            self::Pending, self::Active, self::NoticeGiven => true,
            default => false,
        };
    }
}
