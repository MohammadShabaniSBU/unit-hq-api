<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LogChannel;
use App\Models\Setting;
use Illuminate\Console\Command;
use Spatie\Activitylog\Support\Config;

class PruneActivityLogCommand extends Command
{
    protected $signature = 'activitylog:prune-tiers';

    protected $description = 'Prune tier-2 activity_log channels by configured retention; never touch core';

    public function handle(): int
    {
        $settings = Setting::activityLog();
        $days = max(1, $settings->retentionMonths * 30);

        foreach (LogChannel::optional() as $channel) {
            $deleted = Config::cleanActivityLogAction()->execute($days, $channel->value);
            $this->info("Pruned {$deleted} row(s) from log_name={$channel->value} (older than {$days} days).");
        }

        $this->comment('Skipped log_name=core (indefinite retention).');

        return self::SUCCESS;
    }
}
