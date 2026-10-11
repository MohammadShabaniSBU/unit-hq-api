<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverAdConversions;
use Illuminate\Console\Command;

/**
 * Authoritative sweeper. A lost queue job must not leave a conversion unsent.
 */
class DeliverAdConversionsCommand extends Command
{
    protected $signature = 'ads:deliver-conversions';

    protected $description = 'Deliver pending ad conversion events';

    public function handle(): int
    {
        DeliverAdConversions::dispatch();

        return self::SUCCESS;
    }
}
