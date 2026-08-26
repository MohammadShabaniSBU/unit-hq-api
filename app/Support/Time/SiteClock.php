<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Support-tier helper (same level as BillingMath — never app/Services/) for
 * civil-date boundaries at a site. Date computation for a unit, contract,
 * reservation, or hold resolves through the owning site's timezone.
 */
final class SiteClock
{
    /** Current civil date at the site. */
    public static function today(Site $site): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone($site))->startOfDay();
    }

    /** Civil date at the site for an absolute instant — replaces bare ->toDateString(). */
    public static function dateAt(Site $site, CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone(self::timezone($site))
            ->startOfDay();
    }

    /** Start of the civil day at the site, as an instant. */
    public static function startOfDay(Site $site, CarbonInterface $date): CarbonImmutable
    {
        $civil = CarbonImmutable::parse($date->toDateString(), self::timezone($site));

        return $civil->startOfDay();
    }

    /**
     * Whether `$now` (default now) falls inside the HH:MM window in the site's
     * timezone, or `config('app.timezone')` when `$site` is null.
     *
     * `$end` null means no end — once `$start` has passed, the window is open
     * for the rest of the civil day. An overnight window (`$end` earlier than
     * `$start`) wraps midnight.
     */
    public static function withinWindow(?Site $site, string $start, ?string $end, ?CarbonInterface $now = null): bool
    {
        $tz = $site !== null ? self::timezone($site) : (string) config('app.timezone', 'UTC');
        $instant = CarbonImmutable::instance($now ?? now())->setTimezone($tz);
        $current = $instant->hour * 60 + $instant->minute;
        $startMinutes = self::minutesOfDay($start);

        if ($end === null || $end === '') {
            return $current >= $startMinutes;
        }

        $endMinutes = self::minutesOfDay($end);
        if ($startMinutes === $endMinutes) {
            return true;
        }
        if ($startMinutes < $endMinutes) {
            return $current >= $startMinutes && $current < $endMinutes;
        }

        return $current >= $startMinutes || $current < $endMinutes;
    }

    private static function minutesOfDay(string $hhmm): int
    {
        $parts = explode(':', $hhmm);
        $hour = isset($parts[0]) ? (int) $parts[0] : 0;
        $minute = isset($parts[1]) ? (int) $parts[1] : 0;

        return max(0, min(23, $hour)) * 60 + max(0, min(59, $minute));
    }

    private static function timezone(Site $site): string
    {
        return $site->timezone;
    }
}
