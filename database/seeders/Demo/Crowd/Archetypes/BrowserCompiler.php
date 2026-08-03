<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\ContactLifecycleStatus;
use App\Enums\DealStatus;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Contact + deal; goes quiet or lost; some get lead-chase then exit.
 */
final class BrowserCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng): array
    {
        $enrol = CrowdSupport::enrolDay($rng);
        $fate = $rng->pickWeighted([
            'quiet' => 40,
            'lost' => 30,
            'lead_chase' => 30,
        ]);

        $script = [
            $enrol => static function (DemoWorld $world) use ($handle, $rng, $fate): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng, [
                    'status' => $rng->bool(0.55)
                        ? ContactLifecycleStatus::Prospect
                        : ContactLifecycleStatus::Lead,
                ]);
                $site = CrowdSupport::pickSite($world, $rng);
                $dealStatus = $rng->pick([
                    DealStatus::New,
                    DealStatus::Contacted,
                    DealStatus::Qualified,
                    DealStatus::Negotiating,
                ]);
                JourneySupport::openDeal($world, $handle, $site, $dealStatus);

                if ($fate === 'lead_chase' || $rng->bool(0.25)) {
                    $offerStatus = $rng->pick(['draft', 'sent', 'viewed']);
                    JourneySupport::createOffer(
                        $world,
                        $handle,
                        $site,
                        $rng->pick(CrowdSupport::UNIT_CLASSES),
                        $offerStatus,
                    );
                    if ($offerStatus === 'viewed') {
                        JourneySupport::markOfferViewed($world, $handle);
                    }
                }
            },
        ];

        if ($fate === 'lost') {
            $exit = $enrol + $rng->int(7, 40);
            $script[$exit] = static function (DemoWorld $world) use ($handle): void {
                CrowdSupport::markLost($world, $handle);
            };
        } elseif ($fate === 'lead_chase') {
            // Deal create auto-enrols the live lead-chase playbook; stay quiet until loss.
            $exit = $enrol + $rng->int(14, 40);
            $script[$exit] = static function (DemoWorld $world) use ($handle): void {
                CrowdSupport::markLost($world, $handle);
            };
        } else {
            $quiet = $enrol + $rng->int(5, 21);
            $script[$quiet] = static function (DemoWorld $world) use ($handle): void {
                CrowdSupport::markUnresponsive($world, $handle);
            };
        }

        return $script;
    }
}
