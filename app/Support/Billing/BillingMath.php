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
     * anniversary    -> move_in
     * calendar       -> first day-of-month boundary at or after move_in
     * calendar_week  -> first ISO weekday boundary at or after move_in
     *
     * $anchorDay is day-of-month (1..28) for calendar, or ISO weekday
     * (1=Mon..7=Sun) for calendar_week.
     */
    public static function resolveAnchorDate(
        CarbonImmutable $moveIn,
        BillingAnchorModel|string $anchorModel,
        BillingInterval|string $interval,
        int $intervalCount,
        int $anchorDay = 1,
    ): CarbonImmutable {
        $model = self::anchorModel($anchorModel);
        $moveIn = self::midnight($moveIn);

        if ($model === BillingAnchorModel::Anniversary) {
            return $moveIn;
        }

        if ($model === BillingAnchorModel::CalendarWeek) {
            if (self::interval($interval) !== BillingInterval::Week) {
                throw new InvalidArgumentException('Calendar week anchor model requires a weekly billing interval.');
            }

            return self::nextWeekdayAtOrAfter($moveIn, $anchorDay);
        }

        if (self::interval($interval) !== BillingInterval::Month) {
            throw new InvalidArgumentException('Calendar anchor model requires a monthly billing interval.');
        }

        return self::nextBoundaryAtOrAfter($moveIn, $anchorDay);
    }

    /** The calendar day-of-month boundary <= $date. */
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

    /** The calendar day-of-month boundary >= $date. */
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
     * The ISO weekday boundary <= $date.
     * $isoWeekday is 1=Monday .. 7=Sunday (Carbon dayOfWeekIso).
     */
    public static function previousWeekdayBoundary(CarbonImmutable $date, int $isoWeekday): CarbonImmutable
    {
        $date = self::midnight($date);
        self::assertIsoWeekday($isoWeekday);

        $delta = ($date->dayOfWeekIso - $isoWeekday + 7) % 7;

        return $date->subDays($delta);
    }

    /**
     * The ISO weekday boundary >= $date (same day when already on that weekday).
     * $isoWeekday is 1=Monday .. 7=Sunday (Carbon dayOfWeekIso).
     */
    public static function nextWeekdayAtOrAfter(CarbonImmutable $date, int $isoWeekday): CarbonImmutable
    {
        $date = self::midnight($date);
        self::assertIsoWeekday($isoWeekday);

        $delta = ($isoWeekday - $date->dayOfWeekIso + 7) % 7;

        return $date->addDays($delta);
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
     *
     * $anchorDay is day-of-month for calendar, ISO weekday for calendar_week.
     */
    public static function firstChargeWindow(
        CarbonImmutable $moveIn,
        CarbonImmutable $anchorDate,
        BillingAnchorModel|string $anchorModel,
        int $anchorDay,
    ): ?FirstChargeWindow {
        $moveIn = self::midnight($moveIn);
        $anchorDate = self::midnight($anchorDate);

        if ($anchorDate->equalTo($moveIn)) {
            return null;
        }

        $model = self::anchorModel($anchorModel);

        $periodStart = $model === BillingAnchorModel::CalendarWeek
            ? self::previousWeekdayBoundary($moveIn, $anchorDay)
            : self::previousBoundary($moveIn, $anchorDay);

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

    private static function assertIsoWeekday(int $isoWeekday): void
    {
        if ($isoWeekday < 1 || $isoWeekday > 7) {
            throw new InvalidArgumentException('ISO weekday must be between 1 (Monday) and 7 (Sunday).');
        }
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
