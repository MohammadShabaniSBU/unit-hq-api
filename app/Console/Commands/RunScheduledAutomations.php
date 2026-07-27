<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Jobs\ExecuteAutomationRun;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use Illuminate\Console\Command;

class RunScheduledAutomations extends Command
{
    protected $signature = 'automations:run-scheduled';

    protected $description = 'Scan active trigger.schedule nodes and enqueue due automation runs';

    public function handle(): int
    {
        $automations = Automation::query()
            ->where('status', AutomationStatus::Active)
            ->whereNull('archived_at')
            ->with(['nodes', 'edges'])
            ->get();

        $enqueued = 0;

        foreach ($automations as $automation) {
            $scheduleNodes = $automation->nodes->filter(
                fn (AutomationNode $node) => $node->type === AutomationNodeType::Schedule,
            );

            foreach ($scheduleNodes as $node) {
                if (! $this->isDue($automation, $node)) {
                    continue;
                }

                $run = AutomationRun::query()->create([
                    'automation_id' => $automation->id,
                    'trigger_node_id' => $node->id,
                    'subject_type' => null,
                    'subject_id' => null,
                    'causer_type' => null,
                    'causer_id' => null,
                    'status' => AutomationRunStatus::Pending,
                    'trigger_payload' => [
                        'lifecycle' => 'schedule',
                        'config' => $node->config,
                        'fired_at' => now()->toIso8601String(),
                    ],
                    'depth' => 0,
                ]);

                ExecuteAutomationRun::dispatch($run->id);
                $enqueued++;
            }
        }

        $this->info("Enqueued {$enqueued} scheduled automation run(s).");

        return self::SUCCESS;
    }

    private function isDue(Automation $automation, AutomationNode $node): bool
    {
        $config = $node->config ?? [];
        $frequency = (string) ($config['frequency'] ?? 'daily');

        $last = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('trigger_node_id', $node->id)
            ->whereIn('status', [
                AutomationRunStatus::Pending,
                AutomationRunStatus::Running,
                AutomationRunStatus::Succeeded,
            ])
            ->latest('id')
            ->first();

        if ($last === null) {
            return true;
        }

        return match ($frequency) {
            'once' => false,
            'hourly' => $last->created_at?->lt(now()->subHour()) ?? true,
            'weekly' => $last->created_at?->lt(now()->subWeek()) ?? true,
            'monthly' => $last->created_at?->lt(now()->subMonth()) ?? true,
            default => $last->created_at?->lt(now()->startOfDay()) ?? true, // daily
        };
    }
}
