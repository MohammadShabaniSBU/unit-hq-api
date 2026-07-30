<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Time;

use App\Models\Site;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SiteClockTest extends TestCase
{
    public function test_today_uses_site_timezone(): void
    {
        $madrid = new Site(['timezone' => 'Europe/Madrid']);
        $honolulu = new Site(['timezone' => 'Pacific/Honolulu']);

        // 2026-07-30 22:30 UTC = 2026-07-31 00:30 in Madrid, still 2026-07-30 in Honolulu.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 22:30:00', 'UTC'));

        $this->assertSame('2026-07-31', SiteClock::today($madrid)->toDateString());
        $this->assertSame('2026-07-30', SiteClock::today($honolulu)->toDateString());

        CarbonImmutable::setTestNow();
    }

    public function test_date_at_converts_instant_in_site_zone(): void
    {
        $madrid = new Site(['timezone' => 'Europe/Madrid']);
        $london = new Site(['timezone' => 'Europe/London']);

        $instant = CarbonImmutable::parse('2026-01-15 23:30:00', 'UTC');

        // Madrid winter UTC+1 → 2026-01-16 00:30 local.
        $this->assertSame('2026-01-16', SiteClock::dateAt($madrid, $instant)->toDateString());
        // London winter UTC+0 → 2026-01-15 23:30 local.
        $this->assertSame('2026-01-15', SiteClock::dateAt($london, $instant)->toDateString());
    }

    public function test_does_not_read_app_timezone(): void
    {
        $site = new Site(['timezone' => 'Europe/Madrid']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 22:30:00', 'UTC'));

        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $withUtc = SiteClock::today($site)->toDateString();

        date_default_timezone_set('Pacific/Honolulu');
        $withHonolulu = SiteClock::today($site)->toDateString();

        date_default_timezone_set($previous);
        CarbonImmutable::setTestNow();

        $this->assertSame($withUtc, $withHonolulu);
        $this->assertSame('2026-07-31', $withUtc);
    }
}
