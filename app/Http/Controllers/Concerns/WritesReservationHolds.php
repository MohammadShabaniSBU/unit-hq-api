<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\HoldType;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\Time\SiteClock;

/**
 * Shared by ReservationController::store and OfferOptionController::select —
 * reservation holds join the same transaction as the reservation insert.
 */
trait WritesReservationHolds
{
    /**
     * Assert vacant + unheld, then insert a reservation hold.
     *
     * ends_on is the first day the hold no longer blocks. A reservation
     * expiring at 23:30 on the 14th must still block on the 14th, so ends_on
     * is the 15th — the site-local date of expires_at, plus one day.
     */
    protected function writeReservationHold(Reservation $reservation, Unit $unit, ?int $createdBy = null): UnitHold
    {
        $unit->loadMissing('site');
        $site = $unit->site;

        $startsOn = SiteClock::today($site);
        // Site-local civil date of expires_at, plus one day (half-open [)).
        $endsOn = SiteClock::dateAt($site, $reservation->expires_at)->addDay();

        OccupancyGuard::assertVacant($unit->id, $startsOn, $endsOn);
        HoldGuard::assertUnheld($unit->id, $startsOn, $endsOn);

        return UnitHold::query()->create([
            'unit_id'        => $unit->id,
            'hold_type'      => HoldType::Reservation,
            'reservation_id' => $reservation->id,
            'starts_on'      => $startsOn->format('Y-m-d'),
            'ends_on'        => $endsOn->format('Y-m-d'),
            'released_at'    => null,
            'reason'         => null,
            'created_by'     => $createdBy,
        ]);
    }

    /** Early-release the reservation hold (TIMESTAMP instant). Row survives. */
    protected function releaseReservationHold(Reservation $reservation): void
    {
        UnitHold::query()
            ->where('reservation_id', $reservation->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }
}
