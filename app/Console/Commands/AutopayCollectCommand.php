<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutopayAttemptTrigger;
use App\Support\Payments\AutopayCollector;
use Illuminate\Console\Command;
use ValueError;

/**
 * Collect open due charges off-session for autopay-enabled contracts.
 * Invoked after billing runs (billing_run) and on an hourly sweep.
 */
class AutopayCollectCommand extends Command
{
    protected $signature = 'autopay:collect
                            {--trigger=sweep : billing_run|sweep|manual}
                            {--contract= : Target a single contract id}
                            {--billing-run= : Billing run id to stamp on attempts}';

    protected $description = 'Collect open due charges via off-session autopay';

    public function handle(AutopayCollector $collector): int
    {
        try {
            $trigger = AutopayAttemptTrigger::from((string) $this->option('trigger'));
        } catch (ValueError) {
            $this->error('Invalid --trigger. Use billing_run, sweep, or manual.');

            return self::FAILURE;
        }

        $contractOption = $this->option('contract');
        $contractIds = $contractOption !== null && $contractOption !== ''
            ? [(int) $contractOption]
            : null;

        $billingRunOption = $this->option('billing-run');
        $billingRunId = $billingRunOption !== null && $billingRunOption !== ''
            ? (int) $billingRunOption
            : null;

        $attempts = $collector->collect(
            trigger: $trigger,
            contractIds: $contractIds,
            billingRunId: $billingRunId,
        );

        $this->info(sprintf(
            'Autopay collect finished: trigger=%s attempts=%d',
            $trigger->value,
            count($attempts),
        ));

        return self::SUCCESS;
    }
}
