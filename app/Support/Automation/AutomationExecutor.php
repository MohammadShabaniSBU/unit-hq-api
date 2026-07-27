<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
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

    public function execute(AutomationRun $run): void
    {
        if ($run->depth > self::MAX_DEPTH) {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'error' => 'max depth exceeded',
                'completed_at' => now(),
            ]);

            return;
        }

        $automation = Automation::query()->with(['nodes', 'edges'])->find($run->automation_id);
        if ($automation === null) {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'error' => 'automation missing',
                'completed_at' => now(),
            ]);

            return;
        }

        $run->update([
            'status' => AutomationRunStatus::Running,
            'started_at' => $run->started_at ?? now(),
        ]);

        $context = new RunContext($run->trigger_payload ?? [], [], $run->subject_id);
        $trigger = $automation->nodes->firstWhere('id', $run->trigger_node_id)
            ?? $automation->nodes->first(fn (AutomationNode $n) => $n->type->isTrigger());

        if ($trigger === null) {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'error' => 'trigger node missing',
                'completed_at' => now(),
            ]);

            return;
        }

        // Record trigger as succeeded step (no handler).
        $this->writeStep($run, $trigger, AutomationRunStepStatus::Succeeded, [
            'trigger_payload' => $run->trigger_payload,
        ], [
            'subject_type' => $run->subject_type,
            'subject_id' => $run->subject_id,
        ]);
        $context->putStepOutput($trigger->node_key, [
            'subject_type' => $run->subject_type,
            'subject_id' => $run->subject_id,
            'attributes' => $run->trigger_payload['attributes'] ?? [],
        ]);

        try {
            $this->walk($run, $automation, $trigger, 'default', $context);
            $run->update([
                'status' => AutomationRunStatus::Succeeded,
                'completed_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
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
