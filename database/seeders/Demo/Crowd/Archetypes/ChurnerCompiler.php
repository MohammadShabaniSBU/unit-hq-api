<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use App\Enums\DepositSettlementOutcome;
use App\Models\Site;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * 3–12 month tenures ending in clean vacates across the timeline.
 */
final class ChurnerCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng): array
    {
        $tenureDays = $rng->int(90, 365);
        $span = CrowdSupport::simSpanDays();
        $enrol = CrowdSupport::enrolDay($rng, minTenureDays: $tenureDays, band: 'early');
        $signDay = $enrol + $rng->int(1, 5);
        $vacateDay = min($span - 3, $signDay + $tenureDays);
        if ($vacateDay <= $signDay + 60) {
            $vacateDay = min($span - 3, $signDay + 90);
        }

        return [
            $enrol => static function (DemoWorld $world) use ($handle, $rng): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                JourneySupport::openDeal($world, $handle, $site, DealStatus::Qualified);
            },
            $signDay => static function (DemoWorld $world) use ($handle, $rng, $signDay): void {
                $deal = $world->get("{$handle}.deal");
                $site = Site::query()->findOrFail((int) $deal->site_id);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                JourneySupport::walkInSign(
                    $world,
                    $handle,
                    $unit,
                    CrowdSupport::dateOn($signDay),
                );
                JourneySupport::markSteadyPayer($world, $handle);
            },
            $vacateDay => static function (DemoWorld $world) use ($handle, $vacateDay): void {
                JourneySupport::vacate(
                    $world,
                    $handle,
                    CrowdSupport::dateOn($vacateDay),
                    DepositSettlementOutcome::Released,
                );
            },
        ];
    }
}
