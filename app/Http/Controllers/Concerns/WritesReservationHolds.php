<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Leasing\ReservationHolds;

/**
 * @deprecated Removed when the Copilot tools move onto App\Support\Leasing (S25).
 *
 * Thin shim so Ai/Tools/CreateReservation keeps compiling with zero diff.
 * Hold-writing logic lives in ReservationHolds. No releaseReservationHold —
 * convert calls ReservationHolds::release directly; Copilot never released.
 */
trait WritesReservationHolds
{
    protected function writeReservationHold(Reservation $reservation, Unit $unit, ?int $createdBy = null): UnitHold
    {
        return ReservationHolds::write($reservation, $unit, $createdBy);
    }
}
