<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Contracts\ActivatePendingContracts;
use Illuminate\Console\Command;

/**
 * Hourly job: pending → active when site-today >= move_in_date.
 * Must run at least as often as billing:run (see bootstrap/app.php schedule).
 */
class ActivateContractsCommand extends Command
{
    protected $signature = 'contracts:activate';

    protected $description = 'Activate pending contracts whose move-in date has been reached (site timezone)';

    public function handle(): int
    {
        $result = (new ActivatePendingContracts)->run();

        $this->info(sprintf(
            'Activation finished: activated=%d skipped=%d failed=%d',
            $result['activated'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
