<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\RunLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResumeAutomationRun implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $runId) {}

    public function handle(AutomationExecutor $executor): void
    {
        $run = AutomationRun::query()->find($this->runId);
        if ($run === null || $run->status !== AutomationRunStatus::Waiting) {
            return;
        }

        RunLifecycle::evaluateGuard($run);
        $run->refresh();
        if ($run->status !== AutomationRunStatus::Waiting) {
            return;
        }

        $executor->execute($run);
    }
}
