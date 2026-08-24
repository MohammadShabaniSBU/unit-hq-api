<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use App\Enums\TransferPricingMode;
use App\Models\Site;
use App\Models\Unit;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Historical transfers with rate deltas (up/down/same-class mix).
 */
final class UpsizerDownsizerCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng): array
    {
        $enrol = CrowdSupport::enrolDay($rng, minTenureDays: 120, band: 'early');
        $signDay = $enrol + $rng->int(1, 4);
        $transferDay = $signDay + $rng->int(60, 200);
        $span = CrowdSupport::simSpanDays();
        if ($transferDay >= $span - 5) {
            $transferDay = $span - 10;
        }

        $mode = $rng->bool(0.5)
            ? TransferPricingMode::RetainRate
            : TransferPricingMode::DestinationRate;

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
            $transferDay => static function (DemoWorld $world) use ($handle, $rng, $transferDay, $mode): void {
                /** @var Unit $originUnit */
                $originUnit = $world->get("{$handle}.unit");
                $site = Site::query()->findOrFail((int) $originUnit->site_id);
                $destination = CrowdSupport::vacantUnit($site, $rng);
                JourneySupport::transfer(
                    $world,
                    $handle,
                    $destination,
                    CrowdSupport::dateOn($transferDay),
                    $mode,
                    'Crowd transfer',
                );
            },
        ];
    }
}
