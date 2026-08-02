<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ESign\EnvelopeOrchestrator;
use Illuminate\Console\Command;

class SweepEsignExpiredCommand extends Command
{
    protected $signature = 'esign:sweep-expired';

    protected $description = 'Mark open e-sign envelopes past expires_at as expired';

    public function handle(EnvelopeOrchestrator $orchestrator): int
    {
        $count = $orchestrator->sweepExpired();
        $this->info("Expired {$count} envelope(s).");

        return self::SUCCESS;
    }
}
