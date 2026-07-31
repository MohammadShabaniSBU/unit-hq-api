<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Derived unit inventory state on a civil date (invariant 36).
 * Never stored — computed from unit_occupancies / unit_holds.
 */
enum UnitState: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Maintenance = 'maintenance';
    case Damaged = 'damaged';
    case StaffUse = 'staff_use';
    case Other = 'other';
}
