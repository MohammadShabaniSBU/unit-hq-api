<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Support\Automation\RunLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Opportunistic guard re-check after domain writes. S09 wires callers;
 * this sprint ships the job + test wiring.
 */
class EvaluateRunGuards implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subjectType,
        public readonly int $subjectId,
    ) {}

    public function handle(): void
    {
        $runs = AutomationRun::query()
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->whereNotNull('guard')
            ->whereIn('status', [
                AutomationRunStatus::Pending,
                AutomationRunStatus::Running,
                AutomationRunStatus::Waiting,
            ])
            ->get();

        foreach ($runs as $run) {
            RunLifecycle::evaluateGuard($run);
        }
    }
}
