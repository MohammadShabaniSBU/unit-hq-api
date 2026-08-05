<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiUsageEvent;
use Illuminate\Console\Command;

class SweepAiUsageCommand extends Command
{
    protected $signature = 'ai-usage:sweep';

    protected $description = 'Mark open ai_usage_events older than 30 minutes as orphaned';

    public function handle(): int
    {
        $count = AiUsageEvent::markOrphansOlderThan(now()->subMinutes(30));

        $this->info("Marked {$count} orphaned ai_usage_events row(s).");

        return self::SUCCESS;
    }
}
