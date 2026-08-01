<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Delinquency\DelinquencyEngine;
use Illuminate\Console\Command;

/**
 * Daily (idempotent) delinquency ladder run. Evaluates eligible contracts
 * via DelinquencyEngine — open/cure cases and execute due policy steps.
 */
class DelinquencyRunCommand extends Command
{
    protected $signature = 'delinquency:run
                            {--contract= : Target a single contract id}';

    protected $description = 'Run delinquency evaluation for eligible contracts';

    public function handle(): int
    {
        $contractOption = $this->option('contract');
        $contractId = $contractOption !== null && $contractOption !== ''
            ? (int) $contractOption
            : null;

        $summary = (new DelinquencyEngine)->run($contractId);

        $this->info(sprintf(
            'Delinquency run finished: considered=%d evaluated=%d cured=%d steps=%d failed=%d',
            $summary['considered'],
            $summary['evaluated'],
            $summary['cured'],
            $summary['steps_executed'],
            $summary['failed'],
        ));

        return self::SUCCESS;
    }
}
