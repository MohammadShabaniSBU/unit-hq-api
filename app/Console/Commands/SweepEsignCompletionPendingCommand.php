<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ESign\EnvelopeOrchestrator;
use Illuminate\Console\Command;

class SweepEsignCompletionPendingCommand extends Command
{
    protected $signature = 'esign:sweep-completion-pending';

    protected $description = 'Retry signed-PDF download and contract completion for envelopes stuck in completion_pending';

    public function handle(EnvelopeOrchestrator $orchestrator): int
    {
        $count = $orchestrator->sweepCompletionPending();
        $this->info("Processed {$count} completion-pending envelope(s).");

        return self::SUCCESS;
    }
}
