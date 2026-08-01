<?php

declare(strict_types=1);

namespace App\Enums;

enum HoldType: string
{
    case Reservation = 'reservation';
    case Maintenance = 'maintenance';
    case Damaged = 'damaged';
    case StaffUse = 'staff_use';
    case Overlock = 'overlock';
    case Other = 'other';

    public function blocksAvailability(): bool
    {
        return $this !== self::Overlock;
    }

    public function requiresReason(): bool
    {
        return match ($this) {
            self::Reservation, self::Overlock => false,
            default => true,
        };
    }

    /** Hold types operators may create/release via the units holds API. */
    public function isManuallyManageable(): bool
    {
        return $this !== self::Reservation && $this !== self::Overlock;
    }
}
