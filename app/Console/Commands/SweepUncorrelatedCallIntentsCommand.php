<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallIntent;
use Illuminate\Console\Command;

class SweepUncorrelatedCallIntentsCommand extends Command
{
    protected $signature = 'comms:sweep-uncorrelated-call-intents';

    protected $description = 'Mark requested call intents older than 10 minutes as uncorrelated';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(10);

        $updated = CallIntent::query()
            ->where('status', CallIntent::STATUS_REQUESTED)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => CallIntent::STATUS_UNCORRELATED]);

        $this->info("Marked {$updated} call intent(s) as uncorrelated.");

        return self::SUCCESS;
    }
}
