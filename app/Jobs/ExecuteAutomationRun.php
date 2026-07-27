<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Support\Automation\AutomationExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAutomationRun implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $runId) {}

    public function handle(AutomationExecutor $executor): void
    {
        $run = AutomationRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $executor->execute($run);
    }
}
