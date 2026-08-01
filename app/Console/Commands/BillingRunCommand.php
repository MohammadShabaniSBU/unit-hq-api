<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillingRunTrigger;
use App\Support\Billing\BillingRunEngine;
use Illuminate\Console\Command;
use ValueError;

/**
 * Daily (idempotent) recurring billing run. Advances eligible contracts'
 * billed_through cursors via BillingRunEngine.
 */
class BillingRunCommand extends Command
{
    protected $signature = 'billing:run
                            {--dry-run : Print would-bill table without writing}
                            {--contract= : Target a single contract id}
                            {--trigger=manual : scheduled|manual|retry}';

    protected $description = 'Run recurring billing for eligible contracts';

    public function handle(): int
    {
        try {
            $trigger = BillingRunTrigger::from((string) $this->option('trigger'));
        } catch (ValueError) {
            $this->error('Invalid --trigger. Use scheduled, manual, or retry.');

            return self::FAILURE;
        }

        $contractOption = $this->option('contract');
        $contractId = $contractOption !== null && $contractOption !== ''
            ? (int) $contractOption
            : null;

        $dryRun = (bool) $this->option('dry-run');
        $engine = new BillingRunEngine;

        $result = $engine->run(
            trigger: $trigger,
            contractId: $contractId,
            dryRun: $dryRun,
        );

        if ($dryRun) {
            /** @var list<array{contract_id: int, periods: int, window_start: string|null, window_end: string|null, est_amount: string}> $result */
            if ($result === []) {
                $this->info('No contracts would be billed.');

                return self::SUCCESS;
            }

            $this->table(
                ['contract_id', 'periods', 'window_start', 'window_end', 'est_amount'],
                array_map(fn (array $row) => [
                    $row['contract_id'],
                    $row['periods'],
                    $row['window_start'] ?? '',
                    $row['window_end'] ?? '',
                    $row['est_amount'],
                ], $result),
            );

            return self::SUCCESS;
        }

        /** @var \App\Models\BillingRun $result */
        $this->info(sprintf(
            'Billing run #%d finished: considered=%d billed=%d skipped=%d failed=%d',
            $result->id,
            $result->contracts_considered,
            $result->contracts_billed,
            $result->contracts_skipped,
            $result->contracts_failed,
        ));

        return self::SUCCESS;
    }
}
