<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Support-tier helper (same level as RecordsActivity — never app/Services/) for
 * contract billing cadence, anchor resolution, calendar-days proration, and
 * exclusive tax. Single source used by ContractController::store,
 * ReservationController::convert, and convertPreview so the panel preview and
 * the real generated charges never diverge.
 *
 * Money is always a decimal string, NUMERIC(10,2). bcmath truncates — it never
 * rounds — so every calculation multiplies before dividing at a high
 * intermediate scale and rounds exactly once via round2().
 */
final class BillingMath
{
    private const SCALE = 8;

    /**
     * The contract's billing_anchor_date.
     * anniversary -> move_in. calendar -> first day-of-month boundary at or
     * after move_in (same day if move_in already lands on the boundary).
     */
    public static function resolveAnchorDate(
        CarbonImmutable $moveIn,
        BillingAnchorModel|string $anchorModel,
        BillingInterval|string $interval,
        int $intervalCount,
        int $anchorDayOfMonth = 1,
    ): CarbonImmutable {
        $model = self::anchorModel($anchorModel);
        $moveIn = self::midnight($moveIn);

        if ($model === BillingAnchorModel::Anniversary) {
            return $moveIn;
        }

        if (self::interval($interval) !== BillingInterval::Month) {
            throw new InvalidArgumentException('Calendar anchor model requires a monthly billing interval.');
        }

        return self::nextBoundaryAtOrAfter($moveIn, $anchorDayOfMonth);
    }

    /** The calendar boundary <= $date. */
    public static function previousBoundary(CarbonImmutable $date, int $anchorDayOfMonth): CarbonImmutable
    {
        $date = self::midnight($date);
        $candidate = self::boundaryInMonth($date->year, $date->month, $anchorDayOfMonth);

        if ($candidate->lte($date)) {
            return $candidate;
        }

        $prevMonth = $date->subMonthsNoOverflow(1);

        return self::boundaryInMonth($prevMonth->year, $prevMonth->month, $anchorDayOfMonth);
    }

    /** The calendar boundary >= $date. */
    public static function nextBoundaryAtOrAfter(CarbonImmutable $date, int $anchorDayOfMonth): CarbonImmutable
    {
        $date = self::midnight($date);
        $candidate = self::boundaryInMonth($date->year, $date->month, $anchorDayOfMonth);

        if ($candidate->gte($date)) {
            return $candidate;
        }

        $nextMonth = $date->addMonthsNoOverflow(1);

        return self::boundaryInMonth($nextMonth->year, $nextMonth->month, $anchorDayOfMonth);
    }

    /**
     * Advance one full period from a boundary. Month uses no-overflow addition
     * (Jan 31 +1m -> Feb 28/29) — anniversary cadence has no 1..28 cap on the
     * start day, and this repeats every cycle, so overflow drift compounds.
     */
    public static function advancePeriod(
        CarbonImmutable $from,
        BillingInterval|string $interval,
        int $intervalCount,
    ): CarbonImmutable {
        return match (self::interval($interval)) {
            BillingInterval::Day   => $from->addDays($intervalCount),
            BillingInterval::Week  => $from->addWeeks($intervalCount),
            BillingInterval::Month => $from->addMonthsNoOverflow($intervalCount),
        };
    }

    /**
     * First-charge window. Null means no stub (anniversary, or move_in already
     * lands on the calendar boundary) — caller bills a full period from move_in.
     */
    public static function firstChargeWindow(
        CarbonImmutable $moveIn,
        CarbonImmutable $anchorDate,
        int $anchorDayOfMonth,
    ): ?FirstChargeWindow {
        $moveIn = self::midnight($moveIn);
        $anchorDate = self::midnight($anchorDate);

        if ($anchorDate->equalTo($moveIn)) {
            return null;
        }

        $periodStart = self::previousBoundary($moveIn, $anchorDayOfMonth);
        $daysOccupied = self::daysBetween($moveIn, $anchorDate);
        $daysInPeriod = self::daysBetween($periodStart, $anchorDate);

        return new FirstChargeWindow($moveIn, $anchorDate, $daysOccupied, $daysInPeriod);
    }

    /**
     * Calendar-days proration. Multiplies before dividing (at SCALE=8) so a
     * whole period always prorates back to the exact period amount — dividing
     * first truncates and silently loses cents (e.g. 300/28 * 19 = 203.49
     * instead of the correct 203.57).
     */
    public static function prorate(string $periodAmount, int $daysOccupied, int $daysInPeriod): string
    {
        if ($daysInPeriod <= 0) {
            throw new InvalidArgumentException('daysInPeriod must be positive');
        }

        if ($daysOccupied < 0) {
            throw new InvalidArgumentException('daysOccupied must be non-negative');
        }

        $numerator = bcmul($periodAmount, (string) $daysOccupied, self::SCALE);
        $raw = bcdiv($numerator, (string) $daysInPeriod, self::SCALE);

        return self::round2($raw);
    }

    /**
     * Round a decimal string half-up to 2 places. bcmath truncates, so bias by
     * 0.005 toward the sign, then truncate at scale 2. Correct for + and -.
     */
    public static function round2(string $value): string
    {
        $bias = bccomp($value, '0', self::SCALE) >= 0 ? '0.005' : '-0.005';

        return bcadd(bcadd($value, $bias, self::SCALE), '0', 2);
    }

    /**
     * Exclusive tax breakdown: net is rounded once, tax = round(net * rate / 100),
     * gross = net + tax. Multiply-before-divide, same discipline as prorate().
     */
    public static function applyTax(string $net, ?string $ratePct): TaxBreakdown
    {
        $netR = self::round2($net);

        if ($ratePct === null || bccomp($ratePct, '0', self::SCALE) === 0) {
            return new TaxBreakdown(net: $netR, tax: '0.00', gross: $netR);
        }

        $taxRaw = bcdiv(bcmul($netR, $ratePct, self::SCALE), '100', self::SCALE);
        $tax = self::round2($taxRaw);
        $gross = bcadd($netR, $tax, 2);

        return new TaxBreakdown(net: $netR, tax: $tax, gross: $gross);
    }

    private static function boundaryInMonth(int $year, int $month, int $anchorDayOfMonth): CarbonImmutable
    {
        $base = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $day = min($anchorDayOfMonth, $base->daysInMonth);

        return $base->addDays($day - 1);
    }

    private static function daysBetween(CarbonImmutable $earlier, CarbonImmutable $later): int
    {
        return (int) round(self::midnight($earlier)->diffInDays(self::midnight($later)));
    }

    private static function midnight(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay();
    }

    private static function anchorModel(BillingAnchorModel|string $value): BillingAnchorModel
    {
        return $value instanceof BillingAnchorModel ? $value : BillingAnchorModel::from($value);
    }

    private static function interval(BillingInterval|string $value): BillingInterval
    {
        return $value instanceof BillingInterval ? $value : BillingInterval::from($value);
    }
}
