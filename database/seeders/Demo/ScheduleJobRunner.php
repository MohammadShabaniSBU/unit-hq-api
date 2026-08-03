<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Runs the demo subset of scheduled jobs in product order.
 * Cadence levers trade wall-clock for fidelity on quiet days.
 */
final class ScheduleJobRunner
{
    /**
     * Command signatures in schedule-relevant order
     * (activate before billing — see bootstrap/app.php).
     *
     * @var list<string>
     */
    public const COMMANDS = [
        'contracts:activate',
        'billing:run --trigger=scheduled',
        'autopay:collect --trigger=sweep',
        'delinquency:run',
        'access:sync',
        'automations:resume-waiting',
    ];

    public function __construct(
        private readonly int $delinquencyCadenceDays = 1,
        private readonly int $accessCadenceDays = 7,
    ) {}

    /**
     * @return array{elapsed_ms: float, ran: list<string>, skipped: list<string>}
     */
    public function run(CarbonImmutable $date, DemoWorld $world): array
    {
        $start = hrtime(true);
        $ran = [];
        $skipped = [];

        foreach (self::COMMANDS as $signature) {
            if ($signature === 'delinquency:run' && ! $this->shouldRunDelinquency($date, $world)) {
                $skipped[] = $signature;

                continue;
            }
            if ($signature === 'access:sync' && ! $this->shouldRunAccess($date, $world)) {
                $skipped[] = $signature;

                continue;
            }

            $this->call($signature);
            $ran[] = $signature;
        }

        // One-shot force flags consumed after the tick.
        if ($world->has('jobs.force_delinquency')) {
            $world->remember('jobs.force_delinquency', false);
        }
        if ($world->has('jobs.force_access')) {
            $world->remember('jobs.force_access', false);
        }

        return [
            'elapsed_ms' => (hrtime(true) - $start) / 1_000_000,
            'ran' => $ran,
            'skipped' => $skipped,
        ];
    }

    /**
     * Assert demo commands are registered in bootstrap/app.php, and the
     * activate→billing→autopay→delinquency→access chain keeps relative order
     * (playbook resume is frequency-grouped earlier in the schedule file).
     */
    public static function assertMatchesSchedule(): void
    {
        $path = base_path('bootstrap/app.php');
        $source = File::get($path);

        $ordered = [
            "command('contracts:activate')",
            "command('billing:run --trigger=scheduled')",
            "command('autopay:collect --trigger=sweep')",
            "command('delinquency:run')",
            "command('access:sync')",
        ];

        $lastPos = -1;
        foreach ($ordered as $needle) {
            $pos = strpos($source, $needle);
            if ($pos === false) {
                throw new \RuntimeException("Schedule missing expected registration: {$needle}");
            }
            if ($pos < $lastPos) {
                throw new \RuntimeException("Schedule order drift around: {$needle}");
            }
            $lastPos = $pos;
        }

        if (! str_contains($source, "command('automations:resume-waiting')")) {
            throw new \RuntimeException('Schedule missing automations:resume-waiting');
        }
    }

    private function shouldRunDelinquency(CarbonImmutable $date, DemoWorld $world): bool
    {
        if ($this->delinquencyCadenceDays <= 1) {
            return true;
        }
        if ($world->has('jobs.force_delinquency') && $world->get('jobs.force_delinquency') === true) {
            return true;
        }
        if ($world->hasMissingPayers()) {
            return true;
        }

        return ($date->dayOfYear() % $this->delinquencyCadenceDays) === 0;
    }

    private function shouldRunAccess(CarbonImmutable $date, DemoWorld $world): bool
    {
        if ($this->accessCadenceDays <= 1) {
            return true;
        }
        if ($world->has('jobs.force_access') && $world->get('jobs.force_access') === true) {
            return true;
        }

        return ($date->dayOfYear() % $this->accessCadenceDays) === 0;
    }

    private function call(string $signature): void
    {
        $parts = preg_split('/\s+/', $signature, 2) ?: [$signature];
        $command = $parts[0];
        $args = [];
        if (isset($parts[1])) {
            // Parse --key=value pairs.
            if (preg_match_all('/--([^=\s]+)(?:=(\S+))?/', $parts[1], $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $args['--'.$match[1]] = $match[2] ?? true;
                }
            }
        }

        Artisan::call($command, $args);
    }
}
