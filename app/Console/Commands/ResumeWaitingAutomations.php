<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationRunStatus;
use App\Jobs\ResumeAutomationRun;
use App\Models\AutomationRun;
use Illuminate\Console\Command;

/**
 * Authoritative sweeper for parked waits. Delayed ResumeAutomationRun jobs are
 * latency optimization; a lost delayed job must not strand runs.
 */
class ResumeWaitingAutomations extends Command
{
    protected $signature = 'automations:resume-waiting';

    protected $description = 'Dispatch resume jobs for automation runs whose wait has elapsed';

    public function handle(): int
    {
        $ids = AutomationRun::query()
            ->where('status', AutomationRunStatus::Waiting)
            ->whereNotNull('waiting_until')
            ->where('waiting_until', '<=', now())
            ->orderBy('waiting_until')
            ->pluck('id');

        foreach ($ids as $id) {
            ResumeAutomationRun::dispatch((int) $id);
        }

        $this->info("Dispatched {$ids->count()} resume job(s).");

        return self::SUCCESS;
    }
}
