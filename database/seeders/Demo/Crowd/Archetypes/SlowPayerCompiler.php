<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use App\Models\Site;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Signed tenant who drifts 1–14 days late; always cures via late standing orders.
 */
final class SlowPayerCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng, bool $withDiscount = false): array
    {
        $enrol = CrowdSupport::enrolDay($rng, minTenureDays: 90, band: 'early');
        $signDay = $enrol + $rng->int(1, 6);
        $lag = $rng->int(1, 14);
        $discountPick = $withDiscount ? CrowdSupport::pickDiscount($rng) : null;

        return [
            $enrol => static function (DemoWorld $world) use ($handle, $rng): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                JourneySupport::openDeal($world, $handle, $site, DealStatus::Qualified);
            },
            $signDay => static function (DemoWorld $world) use ($handle, $rng, $signDay, $lag, $discountPick): void {
                $deal = $world->get("{$handle}.deal");
                $site = Site::query()->findOrFail((int) $deal->site_id);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                JourneySupport::walkInSign(
                    $world,
                    $handle,
                    $unit,
                    CrowdSupport::dateOn($signDay),
                    discountId: $discountPick['discount_id'] ?? null,
                    commitmentWeeks: $discountPick['commitment_weeks'] ?? null,
                );
                JourneySupport::markLatePayer($world, $handle, $lag);
            },
        ];
    }
}
