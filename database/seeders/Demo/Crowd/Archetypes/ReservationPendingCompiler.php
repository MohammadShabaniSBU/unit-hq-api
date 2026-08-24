<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Offer accepted near seed-end; reservation still pending with a live hold.
 */
final class ReservationPendingCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng): array
    {
        $enrol = CrowdSupport::enrolDay($rng, band: 'end');
        $expiresAt = CarbonImmutable::parse(CastExecutor::SIM_END)->addDays(7)->endOfDay();

        return [
            $enrol => static function (DemoWorld $world) use ($handle, $rng, $expiresAt): void {
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
                JourneySupport::markOfferViewed($world, $handle);
                JourneySupport::acceptOffer($world, $handle, $expiresAt);
            },
        ];
    }
}
