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
 * Enquiry → offer → walk-in sign within days → steady payer.
 */
final class QuickSignerCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng, bool $withRateChange = false): array
    {
        $enrol = CrowdSupport::enrolDay($rng, minTenureDays: 60);
        $signDay = $enrol + $rng->int(1, 5);

        $script = [
            $enrol => static function (DemoWorld $world) use ($handle, $rng): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                JourneySupport::openDeal($world, $handle, $site, DealStatus::Qualified);
                JourneySupport::createOffer(
                    $world,
                    $handle,
                    $site,
                    $rng->pick(CrowdSupport::UNIT_CLASSES),
                    'sent',
                );
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
        ];

        if ($withRateChange) {
            $changeDay = $signDay + $rng->int(45, 120);
            if ($changeDay < CrowdSupport::simSpanDays() - 7) {
                $script[$changeDay] = static function (DemoWorld $world) use ($handle, $rng, $changeDay): void {
                    $contract = JourneySupport::contract($world, $handle);
                    $item = $contract->items()->where('item_type', 'unit')->whereNull('effective_to')->first();
                    if ($item === null || $item->price === null) {
                        return;
                    }
                    $current = (string) $item->price->amount;
                    $bump = $rng->int(5, 25);
                    $newAmount = bcadd($current, (string) $bump, 2);
                    JourneySupport::scheduleRateChange(
                        $world,
                        $handle,
                        $newAmount,
                        CrowdSupport::dateOn($changeDay),
                        acknowledgeShortNotice: true,
                    );
                };
            }
        }

        return $script;
    }
}
