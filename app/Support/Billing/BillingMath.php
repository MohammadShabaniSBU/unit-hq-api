<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Models\Contract;
use App\Support\Billing\Exceptions\CatchUpCapExceeded;
use App\Support\Billing\Exceptions\MisalignedCursor;
use App\Support\Billing\Exceptions\UnsupportedCadence;
use Carbon\CarbonInterface;
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
 *
 * Recurring windows: nextPeriod / periodsBetween advance full periods from the
 * contract's billed_through cursor (end-exclusive, midnight-normalised).
 */
final class BillingMath
{
    private const SCALE = 8;

    /** Safety bound when walking the anniversary lattice for a cursor. */
    private const MAX_PERIOD_INDEX = 1200;

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
     * Next full period window starting at $cursor (a valid period boundary).
     * End is exclusive. Anniversary ends are computed from the original anchor
     * (addMonthsNoOverflow from anchor + N×count) so month-end windows never drift.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function nextPeriod(Contract $c, CarbonInterface $cursor): array
    {
        $model = self::anchorModel($c->billing_anchor_model);
        $count = (int) $c->billing_interval_count;
        $interval = self::interval($c->billing_interval);

        if (
            ($model === BillingAnchorModel::Calendar || $model === BillingAnchorModel::CalendarWeek)
            && $count > 1
        ) {
            throw UnsupportedCadence::forCalendarIntervalCount($count);
        }

        $cursor = self::midnight(CarbonImmutable::instance($cursor));
        $anchor = self::midnight(CarbonImmutable::parse((string) $c->billing_anchor_date));

        return match ($model) {
            BillingAnchorModel::Anniversary => self::nextAnniversaryPeriod($anchor, $cursor, $interval, $count),
            BillingAnchorModel::Calendar => self::nextCalendarPeriod($anchor, $cursor),
            BillingAnchorModel::CalendarWeek => self::nextCalendarWeekPeriod($anchor, $cursor, $count),
        };
    }

    /**
     * Ordered full-period windows with start <= $until (inclusive horizon).
     * Empty when nothing is due. Exceeding $cap throws CatchUpCapExceeded.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function periodsBetween(
        Contract $c,
        CarbonInterface $cursor,
        CarbonInterface $until,
        int $cap,
    ): array {
        if ($cap < 1) {
            throw new InvalidArgumentException('Catch-up period cap must be at least 1.');
        }

        $until = self::midnight(CarbonImmutable::instance($until));
        $cursor = self::midnight(CarbonImmutable::instance($cursor));
        $windows = [];

        while (true) {
            $window = self::nextPeriod($c, $cursor);

            if ($window['start']->gt($until)) {
                return $windows;
            }

            if (count($windows) + 1 > $cap) {
                throw new CatchUpCapExceeded(count($windows) + 1);
            }

            $windows[] = $window;
            $cursor = $window['end'];
        }
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private static function nextAnniversaryPeriod(
        CarbonImmutable $anchor,
        CarbonImmutable $cursor,
        BillingInterval $interval,
        int $count,
    ): array {
        $n = self::anniversaryPeriodIndex($anchor, $cursor, $interval, $count);
        $start = self::advancePeriod($anchor, $interval, $n * $count);
        $end = self::advancePeriod($anchor, $interval, ($n + 1) * $count);

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private static function nextCalendarPeriod(CarbonImmutable $anchor, CarbonImmutable $cursor): array
    {
        $day = $anchor->day;

        if (! self::nextBoundaryAtOrAfter($cursor, $day)->equalTo($cursor)) {
            throw MisalignedCursor::for($cursor->toDateString());
        }

        $end = self::nextBoundaryAtOrAfter($cursor->addDay(), $day);

        return ['start' => $cursor, 'end' => $end];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private static function nextCalendarWeekPeriod(
        CarbonImmutable $anchor,
        CarbonImmutable $cursor,
        int $count,
    ): array {
        $weekday = $anchor->dayOfWeekIso;

        if ($cursor->dayOfWeekIso !== $weekday) {
            throw MisalignedCursor::for($cursor->toDateString());
        }

        return [
            'start' => $cursor,
            'end' => self::advancePeriod($cursor, BillingInterval::Week, $count),
        ];
    }

    private static function anniversaryPeriodIndex(
        CarbonImmutable $anchor,
        CarbonImmutable $cursor,
        BillingInterval $interval,
        int $count,
    ): int {
        if ($cursor->lt($anchor)) {
            throw MisalignedCursor::for($cursor->toDateString());
        }

        for ($n = 0; $n <= self::MAX_PERIOD_INDEX; $n++) {
            $boundary = self::advancePeriod($anchor, $interval, $n * $count);

            if ($boundary->equalTo($cursor)) {
                return $n;
            }

            if ($boundary->gt($cursor)) {
                throw MisalignedCursor::for($cursor->toDateString());
            }
        }

        throw MisalignedCursor::for($cursor->toDateString());
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
     * Compare two decimal strings via bcmath (never float).
     *
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    public static function cmp(string $a, string $b, int $scale = self::SCALE): int
    {
        return bccomp($a, $b, $scale);
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

    /** Inclusive-exclusive calendar day count between two civil dates. */
    public static function daysBetween(CarbonImmutable $earlier, CarbonImmutable $later): int
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
