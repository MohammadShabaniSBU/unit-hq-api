<?php

declare(strict_types=1);

namespace App\Support\Occupancy;

use App\Models\UnitOccupancy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * One-unit-one-active-occupancy assertions. Static, no state — same tier as
 * CurrencyGuard. The Postgres exclusion constraint is a safety net; this
 * guard is the primary path (422, not 500) and serialises concurrent signings
 * via SELECT … FOR UPDATE inside the caller's transaction.
 */
final class OccupancyGuard
{
    /**
     * Assert unit has no overlapping occupancy on [from, to) (half-open).
     * NULL $to means open-ended.
     *
     * Civil dates only — callers must resolve timestamps via SiteClock before
     * calling. Never uses Carbon::today() / now() / toDateString().
     *
     * @throws ValidationException
     */
    public static function assertVacant(int $unitId, CarbonInterface $from, ?CarbonInterface $to): void
    {
        $fromDay = CarbonImmutable::instance($from)->startOfDay();
        $toDay = $to !== null ? CarbonImmutable::instance($to)->startOfDay() : null;

        // Half-open overlap: [a,b) ∩ [c,d) ≠ ∅ ⇔ a < d ∧ c < b (null end = +∞).
        $exists = UnitOccupancy::query()
            ->where('unit_id', $unitId)
            ->where(function ($query) use ($fromDay): void {
                $query->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $fromDay);
            })
            ->when(
                $toDay !== null,
                fn ($query) => $query->where('started_on', '<', $toDay),
            )
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'unit_id' => [__('errors.occupancy.unit_occupied')],
            ]);
        }
    }
}
