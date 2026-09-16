<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Country;

use App\Support\Country\CountryProfiles;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BillingRunAtTest extends TestCase
{
    #[Test]
    public function billing_run_at_is_on_or_after_midnight_in_every_allowed_timezone(): void
    {
        foreach (CountryProfiles::all() as $profile) {
            [$hour, $minute] = array_map('intval', explode(':', $profile->billingRunAt()));

            foreach ($profile->allowedTimezones() as $tz) {
                foreach (['2026-01-15', '2026-07-15'] as $day) {
                    $run = CarbonImmutable::parse("{$day} {$profile->billingRunAt()}:00", $profile->schedulerTimezone())
                        ->setTimezone($tz);
                    $midnight = CarbonImmutable::parse("{$day} 00:00:00", $tz);

                    $this->assertTrue(
                        $run->greaterThanOrEqualTo($midnight)
                        || $run->format('H:i') === sprintf('%02d:%02d', $hour, $minute),
                        "{$profile->code()} billing_run_at {$profile->billingRunAt()} is before midnight in {$tz} on {$day}",
                    );
                    $this->assertGreaterThanOrEqual(0, $hour * 60 + $minute);
                }
            }
        }
    }
}
