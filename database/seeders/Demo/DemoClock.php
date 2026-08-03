<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Day-stepping harness: setTestNow → cast → crowd → jobs → texture.
 * No outer transaction — each day commits so afterCommit hooks fire in-step.
 */
final class DemoClock
{
    public function __construct(
        private readonly ScheduleJobRunner $jobs = new ScheduleJobRunner,
        private readonly ?DayTexture $texture = null,
    ) {}

    /**
     * @param  callable(CarbonImmutable $date, DemoWorld $world): void  $eachDay  cast+crowd steps
     * @return array{
     *     days: int,
     *     elapsed_ms: float,
     *     phases: array{cast_crowd: float, jobs: float, standing: float, texture: float}
     * }
     */
    public function run(
        CarbonImmutable $from,
        CarbonImmutable $to,
        DemoWorld $world,
        callable $eachDay,
    ): array {
        $start = hrtime(true);
        $days = 0;
        $phaseMs = [
            'cast_crowd' => 0.0,
            'jobs' => 0.0,
            'standing' => 0.0,
            'texture' => 0.0,
        ];

        $cursor = $from->startOfDay();
        $end = $to->startOfDay();

        while ($cursor->lte($end)) {
            // Canonical noon UTC; SiteClock resolves site-local civil dates.
            $instant = $cursor->setTime(12, 0, 0);
            Carbon::setTestNow($instant);
            CarbonImmutable::setTestNow($instant);

            $t0 = hrtime(true);
            $eachDay($cursor, $world);
            $phaseMs['cast_crowd'] += (hrtime(true) - $t0) / 1_000_000;

            $t1 = hrtime(true);
            JourneySupport::tickStandingOrders($world);
            $phaseMs['standing'] += (hrtime(true) - $t1) / 1_000_000;

            $t2 = hrtime(true);
            $this->jobs->run($cursor, $world);
            $phaseMs['jobs'] += (hrtime(true) - $t2) / 1_000_000;

            if ($this->texture !== null) {
                $t3 = hrtime(true);
                $this->texture->run($cursor, $world);
                $phaseMs['texture'] += (hrtime(true) - $t3) / 1_000_000;
            }

            $days++;

            // Release cyclic graphs accumulated across standing orders / jobs / texture.
            if ($days % 14 === 0) {
                gc_collect_cycles();
            }

            $cursor = $cursor->addDay();
        }

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        return [
            'days' => $days,
            'elapsed_ms' => (hrtime(true) - $start) / 1_000_000,
            'phases' => $phaseMs,
        ];
    }
}
