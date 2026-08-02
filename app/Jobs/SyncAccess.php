<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Access\AccessReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scoped (or full) access reconciliation. Dispatched by AccessSync::nudge
 * after fact writers commit; the hourly access:sync is the sweeper.
 */
class SyncAccess implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $siteId = null,
        public readonly ?int $contractId = null,
        public readonly bool $withDrift = false,
    ) {}

    public function handle(AccessReconciler $reconciler): void
    {
        $reconciler->run(
            siteId: $this->siteId,
            contractId: $this->contractId,
            dryRun: false,
            withDrift: $this->withDrift,
        );
    }
}
