<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Database\Seeders\AgentInboxDraftSeeder;
use Database\Seeders\AiAgentSeeder;
use Database\Seeders\Demo\Crowd\CrowdGenerator;
use Database\Seeders\Demo\Crowd\DemoRng;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Programmatic demo seed (command + tests share one path).
 */
final class DemoPipeline
{
    /**
     * @return array{
     *     world: DemoWorld,
     *     days: int,
     *     elapsed_ms: float,
     *     generate_ms: float,
     *     phases: array{cast_crowd: float, jobs: float, standing: float, texture: float},
     *     crowd_count: int
     * }
     */
    public static function run(Application $app, bool $withCrowd = true): array
    {
        Config::set('queue.default', 'sync');
        self::hushObservability();
        DemoHttpFakes::install();

        $world = new DemoWorld;
        DemoWorld::setCurrent($world);

        $seeder = new StageSeeder;
        $seeder->setContainer($app);
        $seeder->run();

        $agents = new AiAgentSeeder;
        $agents->setContainer($app);
        $agents->run();

        $world->hydrateFromDatabase();

        $from = CarbonImmutable::parse(CastExecutor::SIM_START)->startOfDay();
        $to = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay();
        $cast = new CastExecutor;

        $crowd = new CrowdExecutor([]);
        $texture = null;
        $crowdCount = 0;
        $generateMs = 0.0;

        if ($withCrowd) {
            $rng = DemoRng::fromEnv();
            $genStart = hrtime(true);
            $scripts = (new CrowdGenerator($rng))->compile($world);
            $generateMs = (hrtime(true) - $genStart) / 1_000_000;
            $crowd = new CrowdExecutor($scripts);
            $texture = new DayTexture($rng);
            $crowdCount = $world->has('crowd.count') ? (int) $world->get('crowd.count') : count($scripts);
        }

        $jobs = new ScheduleJobRunner(
            delinquencyCadenceDays: 7,
            accessCadenceDays: 7,
        );

        $clock = new DemoClock($jobs, $texture);
        $result = $clock->run(
            $from,
            $to,
            $world,
            static function (CarbonImmutable $date, DemoWorld $w) use ($cast, $crowd, $from): void {
                $cast->runDue($date, $w, $from);
                $crowd->runDue($date, $w, $from);
            },
        );

        InboxContentBootstrap::apply($world);
        InboxStaging::apply($world);
        (new AgentInboxDraftSeeder)->run();

        return [
            'world' => $world,
            'days' => $result['days'],
            'elapsed_ms' => $result['elapsed_ms'],
            'generate_ms' => $generateMs,
            'phases' => $result['phases'],
            'crowd_count' => $crowdCount,
        ];
    }

    /**
     * Hydrate stage + providers, then only bootstrap inbox email/call threads.
     * Skips the 14‑month clock — for quick refill against an existing demo DB.
     *
     * @return array{world: DemoWorld}
     */
    public static function runInboxOnly(Application $app): array
    {
        Config::set('queue.default', 'sync');
        self::hushObservability();
        DemoHttpFakes::install();

        $world = new DemoWorld;
        DemoWorld::setCurrent($world);
        $world->hydrateFromDatabase();

        InboxContentBootstrap::apply($world);
        InboxStaging::apply($world);
        (new AgentInboxDraftSeeder)->run();

        return ['world' => $world];
    }

    /**
     * 420 simulated days × queries/jobs will OOM Telescope's in-memory entry
     * queue (and stack-trace watcher) under a 512MB limit. Mute for the run.
     */
    private static function hushObservability(): void
    {
        Config::set('telescope.enabled', false);

        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::stopRecording();
            \Laravel\Telescope\Telescope::flushEntries();
        }

        foreach (DB::getConnections() as $connection) {
            $connection->disableQueryLog();
        }
    }
}
