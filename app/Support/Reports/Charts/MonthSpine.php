<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

use Carbon\CarbonImmutable;

/**
 * Civil-month helpers. Reports group by month in PHP so SQLite and Postgres
 * agree. The spine is oldest-first and never longer than 24 months.
 */
final class MonthSpine
{
    public const MAX_MONTHS = 24;

    /**
     * @return list<string> YYYY-MM, oldest first
     */
    public static function months(string $to, ?string $from = null, int $count = 12): array
    {
        $end = CarbonImmutable::parse($to)->startOfMonth();

        if ($from !== null && $from !== '') {
            $start = CarbonImmutable::parse($from)->startOfMonth();
        } else {
            $span = max(1, $count);
            $start = $end->subMonthsNoOverflow($span - 1);
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $months = [];
        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonthNoOverflow();
        }

        if (count($months) > self::MAX_MONTHS) {
            $months = array_slice($months, -self::MAX_MONTHS);
        }

        return array_values($months);
    }

    public static function firstDay(string $month): string
    {
        return CarbonImmutable::parse($month.'-01')->startOfMonth()->toDateString();
    }

    public static function lastDay(string $month): string
    {
        return CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString();
    }

    public static function of(string $date): string
    {
        return CarbonImmutable::parse($date)->format('Y-m');
    }

    /**
     * Floor of whole calendar months from $from to $to.
     */
    public static function monthsBetween(string $from, string $to): int
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $months = ($end->year - $start->year) * 12 + ($end->month - $start->month);
        if ($end->day < $start->day) {
            $months--;
        }

        return $months;
    }
}
