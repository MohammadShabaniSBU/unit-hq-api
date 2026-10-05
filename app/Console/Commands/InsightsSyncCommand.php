<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Insights\NativeReportSync;
use Illuminate\Console\Command;

/**
 * Insert missing native insight_reports rows, then run insights:check.
 * Does not migrate, update, or delete.
 */
class InsightsSyncCommand extends Command
{
    protected $signature = 'insights:sync {--dry-run : List missing keys without writing}';

    protected $description = 'Insert missing native insight report rows, then check registry drift';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keys = NativeReportSync::run($dryRun);

        if ($keys === []) {
            $this->info($dryRun
                ? 'insights:sync — no rows would be inserted.'
                : 'insights:sync — no rows inserted.');
        } else {
            $verb = $dryRun ? 'would insert' : 'inserted';
            foreach ($keys as $key) {
                $this->line("{$verb} [{$key}]");
            }
        }

        return $this->call('insights:check');
    }
}
