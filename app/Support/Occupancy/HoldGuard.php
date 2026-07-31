<?php

declare(strict_types=1);

namespace App\Support\Occupancy;

use App\Enums\HoldType;
use App\Models\UnitHold;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * One-unit blocking-hold assertions. Static, no state — same tier as
 * OccupancyGuard / CurrencyGuard. The Postgres exclusion constraint is a
 * safety net; this guard is the primary path (422, not 500) and serialises
 * concurrent writers via SELECT … FOR UPDATE inside the caller's transaction.
 *
 * Overlock holds are excluded — they coexist with occupancy by definition.
 */
final class HoldGuard
{
    /**
     * Assert unit has no overlapping unreleased blocking hold on [from, to)
     * (half-open). NULL $to means open-ended.
     *
     * Civil dates only — callers must resolve timestamps via SiteClock before
     * calling. Never uses Carbon::today() / now() / toDateString() on timestamps.
     *
     * @throws ValidationException
     */
    public static function assertUnheld(int $unitId, CarbonInterface $from, ?CarbonInterface $to): void
    {
        $fromDay = CarbonImmutable::instance($from)->startOfDay();
        $toDay = $to !== null ? CarbonImmutable::instance($to)->startOfDay() : null;

        // Half-open overlap: [a,b) ∩ [c,d) ≠ ∅ ⇔ a < d ∧ c < b (null end = +∞).
        $exists = UnitHold::query()
            ->where('unit_id', $unitId)
            ->whereNull('released_at')
            ->where('hold_type', '<>', HoldType::Overlock->value)
            ->where(function ($query) use ($fromDay): void {
                $query->whereNull('ends_on')
                    ->orWhere('ends_on', '>', $fromDay);
            })
            ->when(
                $toDay !== null,
                fn ($query) => $query->where('starts_on', '<', $toDay),
            )
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'unit_id' => [__('errors.holds.unit_held')],
            ]);
        }
    }
}
