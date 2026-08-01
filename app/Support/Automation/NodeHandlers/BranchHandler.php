<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\ConditionSource;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\ConditionContext;
use App\Support\Automation\ConditionEvaluator;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;

/**
 * Evaluates branch conditions against the trigger snapshot. Returns handle true|false.
 * Executor uses output['handle'] to pick the outgoing edge.
 */
final class BranchHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $filters = $config['filters'] ?? $config['condition'] ?? ['logic' => 'and', 'conditions' => []];

        $values = $this->flattenContext($context);
        $evalContext = new ConditionContext(
            source: ConditionSource::Snapshot,
            entityType: $run->subject_type,
        );

        $result = is_array($filters)
            ? ConditionEvaluator::evaluate($filters, $values, $evalContext)
            : ConditionEvaluator::evaluate(
                ['logic' => 'and', 'conditions' => []],
                $values,
                $evalContext,
            );

        return [
            'handle' => $result->passed ? 'true' : 'false',
            'passed' => $result->passed,
            'warnings' => $result->warnings,
            'source' => ConditionSource::Snapshot->value,
        ];
    }

    /** @return array<string, mixed> */
    private function flattenContext(RunContext $context): array
    {
        $values = [];

        $natives = $context->triggerPayload['attributes'] ?? null;
        if (is_array($natives)) {
            foreach ($natives as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $values[(string) $key] = $value;
                }
            }
        }

        foreach ($context->triggerPayload as $key => $value) {
            if ($key === 'attributes' || $key === 'custom_attributes' || $key === 'dirty' || $key === 'lifecycle') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $values[(string) $key] = $value;
            }
        }

        $custom = $context->triggerPayload['custom_attributes'] ?? null;
        if (is_array($custom)) {
            foreach ($custom as $key => $value) {
                $values[(string) $key] = $value;
            }
        }

        return $values;
    }
}
