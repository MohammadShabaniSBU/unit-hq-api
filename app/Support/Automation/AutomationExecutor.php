<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Jobs\ResumeAutomationRun;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\NodeHandlers\BranchHandler;
use App\Support\Automation\NodeHandlers\CreateObjectHandler;
use App\Support\Automation\NodeHandlers\SendEmailHandler;
use App\Support\Automation\NodeHandlers\UpdateObjectHandler;
use App\Support\Automation\NodeHandlers\WaitHandler;
use Throwable;

/**
 * Walks the automation graph for one run, writing append-only step rows.
 */
final class AutomationExecutor
{
    private const MAX_DEPTH = 5;

    /** @var array<string, class-string<NodeHandler>> */
    private const HANDLERS = [
        'action.update_object' => UpdateObjectHandler::class,
        'action.create_object' => CreateObjectHandler::class,
        'action.send_email' => SendEmailHandler::class,
        'logic.branch' => BranchHandler::class,
        'logic.wait' => WaitHandler::class,
    ];

    /**
     * Registered node-type → handler map (for harness coverage gates).
     *
     * @return array<string, class-string<NodeHandler>>
     */
    public static function handlers(): array
    {
        return self::HANDLERS;
    }

    public function execute(AutomationRun $run): void
    {
        if ($run->depth > self::MAX_DEPTH) {
            RunLifecycle::fail($run, 'max depth exceeded');

            return;
        }

        $automation = Automation::query()->with(['nodes', 'edges'])->find($run->automation_id);

        $isResume = $run->status === AutomationRunStatus::Waiting
            || (
                $run->status === AutomationRunStatus::Running
                && $run->current_node_id !== null
                && $this->hasWaitingStep($run)
            );

        if ($run->status === AutomationRunStatus::Pending || $run->status === AutomationRunStatus::Waiting) {
            if (! RunLifecycle::claimRunning($run)) {
                return;
            }
        } elseif ($run->status !== AutomationRunStatus::Running) {
            return;
        }

        if ($automation === null) {
            RunLifecycle::fail($run, 'automation missing');

            return;
        }

        RunLifecycle::evaluateGuard($run);
        $run->refresh();
        if ($run->status === AutomationRunStatus::Cancelled) {
            return;
        }

        $context = $this->buildContext($run, $automation);
        $trigger = $automation->nodes->firstWhere('id', $run->trigger_node_id)
            ?? $automation->nodes->first(fn (AutomationNode $n) => $n->type->isTrigger());

        if ($trigger === null) {
            RunLifecycle::fail($run, 'trigger node missing');

            return;
        }

        try {
            if ($isResume) {
                $cursor = $automation->nodes->firstWhere('id', $run->current_node_id);
                if ($cursor === null) {
                    RunLifecycle::fail($run, 'resume cursor missing');

                    return;
                }

                $this->completeWaitingStep($run);
                $this->walk($run, $automation, $cursor, 'default', $context);
            } else {
                if (! $this->hasTriggerStep($run, $trigger)) {
                    $this->writeStep($run, $trigger, AutomationRunStepStatus::Succeeded, [
                        'trigger_payload' => $run->trigger_payload,
                    ], [
                        'subject_type' => $run->subject_type,
                        'subject_id' => $run->subject_id,
                    ]);
                }
                $context->putStepOutput($trigger->node_key, [
                    'subject_type' => $run->subject_type,
                    'subject_id' => $run->subject_id,
                    'attributes' => $run->trigger_payload['attributes'] ?? [],
                ]);

                $this->walk($run, $automation, $trigger, 'default', $context);
            }

            $run->refresh();
            if ($run->status === AutomationRunStatus::Running) {
                RunLifecycle::succeed($run);
            }
        } catch (Parked $parked) {
            if (RunLifecycle::park($run, $parked->until, $parked->nodeId)) {
                ResumeAutomationRun::dispatch($run->id)->delay($parked->until);
            }
        } catch (Throwable $e) {
            RunLifecycle::fail($run->fresh() ?? $run, $e->getMessage());
            report($e);
        }
    }

    private function walk(
        AutomationRun $run,
        Automation $automation,
        AutomationNode $from,
        string $handle,
        RunContext $context,
    ): void {
        $run->refresh();
        if ($run->status !== AutomationRunStatus::Running) {
            return;
        }

        RunLifecycle::evaluateGuard($run);
        $run->refresh();
        if ($run->status !== AutomationRunStatus::Running) {
            return;
        }

        $edges = $automation->edges
            ->where('source_node_id', $from->id)
            ->values();

        if ($edges->isEmpty()) {
            return;
        }

        $chosen = $edges->first(
            fn (AutomationEdge $edge) => ($edge->source_handle ?: 'default') === $handle,
        );

        foreach ($edges as $edge) {
            $edgeHandle = $edge->source_handle ?: 'default';
            if ($chosen !== null && $edge->id !== $chosen->id) {
                $skipped = $automation->nodes->firstWhere('id', $edge->target_node_id);
                if ($skipped !== null) {
                    $this->writeStep($run, $skipped, AutomationRunStepStatus::Skipped, [
                        'reason' => "branch handle '{$handle}' not taken (edge handle '{$edgeHandle}')",
                    ], null);
                    $this->skipDownstream($run, $automation, $skipped);
                }
            }
        }

        if ($chosen === null) {
            return;
        }

        $next = $automation->nodes->firstWhere('id', $chosen->target_node_id);
        if ($next === null) {
            return;
        }

        if ($next->type->isTrigger()) {
            return;
        }

        $run->refresh();
        if ($run->status !== AutomationRunStatus::Running) {
            return;
        }

        RunLifecycle::evaluateGuard($run);
        $run->refresh();
        if ($run->status !== AutomationRunStatus::Running) {
            return;
        }

        $output = $this->executeNode($run, $next, $context);
        $nextHandle = 'default';

        if ($next->type === AutomationNodeType::Branch) {
            $nextHandle = (string) ($output['handle'] ?? 'false');
        }

        $this->walk($run, $automation, $next, $nextHandle, $context);
    }

    private function skipDownstream(AutomationRun $run, Automation $automation, AutomationNode $from): void
    {
        foreach ($automation->edges->where('source_node_id', $from->id) as $edge) {
            $node = $automation->nodes->firstWhere('id', $edge->target_node_id);
            if ($node === null) {
                continue;
            }
            $this->writeStep($run, $node, AutomationRunStepStatus::Skipped, [
                'reason' => 'upstream branch path not taken',
            ], null);
            $this->skipDownstream($run, $automation, $node);
        }
    }

    /** @return array<string, mixed> */
    private function executeNode(AutomationRun $run, AutomationNode $node, RunContext $context): array
    {
        $handlerClass = self::HANDLERS[$node->type->value] ?? null;
        if ($handlerClass === null) {
            $this->writeStep($run, $node, AutomationRunStepStatus::Failed, $node->config ?? [], null, [
                'message' => "no handler for {$node->type->value}",
            ]);

            throw new \RuntimeException("no handler for {$node->type->value}");
        }

        /** @var NodeHandler $handler */
        $handler = app($handlerClass);
        $started = hrtime(true);
        $step = AutomationRunStep::query()->create([
            'run_id' => $run->id,
            'node_id' => $node->id,
            'node_type' => $node->type->value,
            'status' => AutomationRunStepStatus::Running,
            'input' => $node->config,
            'started_at' => now(),
        ]);

        try {
            $output = $handler->handle($run, $step, $node, $context);
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $step->update([
                'status' => AutomationRunStepStatus::Succeeded,
                'output' => $output,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ]);
            $context->putStepOutput($node->node_key, $output);

            return $output;
        } catch (Parked $parked) {
            throw $parked;
        } catch (Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $step->update([
                'status' => AutomationRunStepStatus::Failed,
                'error' => ['message' => $e->getMessage()],
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ]);

            throw $e;
        }
    }

    private function buildContext(AutomationRun $run, Automation $automation): RunContext
    {
        $stepOutputs = [];

        $steps = AutomationRunStep::query()
            ->where('run_id', $run->id)
            ->where('status', AutomationRunStepStatus::Succeeded)
            ->whereNotNull('node_id')
            ->orderBy('id')
            ->get();

        foreach ($steps as $step) {
            $node = $automation->nodes->firstWhere('id', $step->node_id);
            if ($node === null) {
                continue;
            }
            $stepOutputs[$node->node_key] = $step->output ?? [];
        }

        return new RunContext($run->trigger_payload ?? [], $stepOutputs, $run->subject_id);
    }

    private function hasWaitingStep(AutomationRun $run): bool
    {
        return AutomationRunStep::query()
            ->where('run_id', $run->id)
            ->where('status', AutomationRunStepStatus::Waiting)
            ->exists();
    }

    private function hasTriggerStep(AutomationRun $run, AutomationNode $trigger): bool
    {
        return AutomationRunStep::query()
            ->where('run_id', $run->id)
            ->where('node_id', $trigger->id)
            ->exists();
    }

    private function completeWaitingStep(AutomationRun $run): void
    {
        $step = AutomationRunStep::query()
            ->where('run_id', $run->id)
            ->where('status', AutomationRunStepStatus::Waiting)
            ->orderByDesc('id')
            ->first();

        if ($step === null) {
            return;
        }

        $output = $step->output ?? [];
        $output['resumed_at'] = now()->toIso8601String();
        $started = $step->started_at;
        $durationMs = $started !== null
            ? (int) $started->diffInMilliseconds(now())
            : 0;

        $step->update([
            'status' => AutomationRunStepStatus::Succeeded,
            'output' => $output,
            'completed_at' => now(),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>|null  $error
     */
    private function writeStep(
        AutomationRun $run,
        AutomationNode $node,
        AutomationRunStepStatus $status,
        ?array $input,
        ?array $output,
        ?array $error = null,
    ): void {
        AutomationRunStep::query()->create([
            'run_id' => $run->id,
            'node_id' => $node->id,
            'node_type' => $node->type->value,
            'status' => $status,
            'input' => $input,
            'output' => $output,
            'error' => $error,
            'started_at' => now(),
            'completed_at' => now(),
            'duration_ms' => 0,
        ]);
    }
}
