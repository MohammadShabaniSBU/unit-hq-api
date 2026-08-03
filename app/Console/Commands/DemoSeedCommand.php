<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoPipeline;
use Database\Seeders\Demo\DemoScript;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Opt-in demo world seeder. Replays history through the real schedule + injectors.
 * Task 04: prints + persists the presenter demo script after the pipeline.
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
                            {--fresh : migrate:fresh then seed the empty stage before the demo pipeline}
                            {--cast-only : skip crowd generation (debug / cast isolation)}
                            {--inbox-only : skip the clock; bootstrap email + call threads on the existing stage DB}';

    protected $description = 'Seed the living demo facility (opt-in; refuses on production)';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:seed refuses to run in the production environment.');

            return self::FAILURE;
        }

        if ($this->option('inbox-only')) {
            return $this->runInboxOnly();
        }

        $totalStart = hrtime(true);

        if ($this->option('fresh')) {
            $this->info('migrate:fresh…');
            Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        }

        $withCrowd = ! $this->option('cast-only');
        $this->info($withCrowd
            ? 'Running demo pipeline (cast + crowd)…'
            : 'Running demo pipeline (cast only)…');

        try {
            $result = DemoPipeline::run($this->laravel, $withCrowd);
        } finally {
            DemoWorld::setCurrent(null);
        }

        $totalMs = (hrtime(true) - $totalStart) / 1_000_000;
        $phases = $result['phases'];

        $this->newLine();
        $this->info('Demo seed complete.');
        $this->table(
            ['Phase', 'Duration'],
            [
                ['generate crowd', sprintf('%.0f ms', $result['generate_ms'])],
                ['cast+crowd steps', sprintf('%.0f ms', $phases['cast_crowd'])],
                ['standing orders', sprintf('%.0f ms', $phases['standing'])],
                ['jobs', sprintf('%.0f ms', $phases['jobs'])],
                ['texture', sprintf('%.0f ms', $phases['texture'])],
                ['clock ('.$result['days'].' days)', sprintf('%.0f ms', $result['elapsed_ms'])],
                ['total', sprintf('%.0f ms (%.1f min)', $totalMs, $totalMs / 60_000)],
            ],
        );

        if ($totalMs > 300_000) {
            $this->warn(sprintf('Wall-clock %.1f min exceeds 5-minute target.', $totalMs / 60_000));
        }

        $script = DemoScript::render();
        $scriptPath = DemoScript::write(contents: $script);
        $this->newLine();
        $this->info('Demo script written to '.$scriptPath);
        $this->line($script);

        return self::SUCCESS;
    }

    private function runInboxOnly(): int
    {
        if ($this->option('fresh')) {
            $this->error('--inbox-only cannot be combined with --fresh.');

            return self::FAILURE;
        }

        $this->info('Bootstrapping inbox email + call threads…');

        $start = hrtime(true);

        try {
            DemoPipeline::runInboxOnly($this->laravel);
        } finally {
            DemoWorld::setCurrent(null);
        }

        $ms = (hrtime(true) - $start) / 1_000_000;
        $this->info(sprintf('Inbox bootstrap complete (%.0f ms).', $ms));

        return self::SUCCESS;
    }
}
