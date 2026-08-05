<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiUsageEvent;
use Illuminate\Console\Command;

class PruneAiUsageCommand extends Command
{
    protected $signature = 'ai-usage:prune';

    protected $description = 'Delete ai_usage_events older than 24 months';

    public function handle(): int
    {
        $deleted = AiUsageEvent::query()
            ->where('started_at', '<', now()->subMonths(24))
            ->delete();

        $this->info("Pruned {$deleted} ai_usage_events row(s) older than 24 months.");

        return self::SUCCESS;
    }
}
