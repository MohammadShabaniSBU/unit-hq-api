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

    private static function timezone(Site $site): string
    {
        return $site->timezone;
    }
}
