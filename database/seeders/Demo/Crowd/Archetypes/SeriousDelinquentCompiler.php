<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\ContractEndedReason;
use App\Enums\DealStatus;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Deep miss; subset reaches overlock; one vacates non-payment.
 * Miss windows are staggered so every ageing bucket is populated at seed-end.
 */
final class SeriousDelinquentCompiler
{
    /**
     * @param  'deep'|'overlock'|'vacate'  $path
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(string $handle, DemoRng $rng, string $path, int $targetDaysOverdue = 40): array
    {
        $span = CrowdSupport::simSpanDays();
        $signDay = $rng->int(40, 120);
        $missFrom = max($signDay + 30, $span - max(5, $targetDaysOverdue));

        $script = [
            $signDay => static function (DemoWorld $world) use ($handle, $rng, $signDay): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                JourneySupport::openDeal($world, $handle, $site, DealStatus::Qualified);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                JourneySupport::walkInSign(
                    $world,
                    $handle,
                    $unit,
                    CrowdSupport::dateOn($signDay),
                );
                JourneySupport::markSteadyPayer($world, $handle);
            },
            $missFrom => static function (DemoWorld $world) use ($handle): void {
                JourneySupport::startMissingPayments($world, $handle);
                $world->remember('jobs.force_delinquency', true);
            },
        ];

        $callDay = min($span - 3, $missFrom + 8);
        $script[$callDay] = static function (DemoWorld $world) use ($handle, $rng): void {
            JourneySupport::recordCallWrapup(
                $world,
                $handle,
                $rng->bool(0.5) ? 'payment_promised' : 'no_answer',
                'Serious delinquent collections call',
                direction: 'outbound',
            );
        };

        if ($path === 'overlock') {
            $doorDay = min($span - 2, $missFrom + 18);
            $script[$doorDay] = static function (DemoWorld $world) use ($handle): void {
                JourneySupport::doorDenied($world, $handle);
                $world->remember('jobs.force_access', true);
            };
        }

        if ($path === 'vacate') {
            $writeOffDay = $span - 10;
            $vacateDay = $span - 8;
            $script[$writeOffDay] = static function (DemoWorld $world) use ($handle): void {
                JourneySupport::writeOff($world, $handle, 'Crowd non-payment write-off');
            };
            $script[$vacateDay] = static function (DemoWorld $world) use ($handle, $vacateDay): void {
                JourneySupport::vacate(
                    $world,
                    $handle,
                    CrowdSupport::dateOn($vacateDay),
                    endedReason: ContractEndedReason::NonPayment,
                );
            };
        }

        return $script;
    }
}
