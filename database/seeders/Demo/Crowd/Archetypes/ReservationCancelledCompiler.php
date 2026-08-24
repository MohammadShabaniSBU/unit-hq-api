<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Accepted, then cancelled with the hold released.
 */
final class ReservationCancelledCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng): array
    {
        $enrol = CrowdSupport::enrolDay($rng, band: 'mid');
        $acceptDay = $enrol + $rng->int(1, 5);
        $cancelDay = $acceptDay + $rng->int(2, 8);

        return [
            $enrol => static function (DemoWorld $world) use ($handle, $rng): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                $class = $unit->unitClass()->first();
                JourneySupport::openDeal($world, $handle, $site, DealStatus::OfferSent);
                JourneySupport::createOffer(
                    $world,
                    $handle,
                    $site,
                    $class?->code ?? 'SS4',
                    'sent',
                    unit: $unit,
                );
            },
            $acceptDay => static function (DemoWorld $world) use ($handle): void {
                JourneySupport::markOfferViewed($world, $handle);
                JourneySupport::acceptOffer($world, $handle);
            },
            $cancelDay => static function (DemoWorld $world) use ($handle): void {
                JourneySupport::cancelReservation($world, $handle);
                CrowdSupport::markLost($world, $handle);
            },
        ];
    }
}
