<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\ConditionEvaluator;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;

/**
 * Evaluates branch conditions. Returns which handle to follow: true|false.
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
        $passed = is_array($filters)
            ? ConditionEvaluator::matchesGroup($filters, $values)
            : false;

        return [
            'handle' => $passed ? 'true' : 'false',
            'passed' => $passed,
        ];
    }

    /** @return array<string, mixed> */
    private function flattenContext(RunContext $context): array
    {
        $values = [];

        foreach ($context->triggerPayload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $values[(string) $key] = $value;
            }
        }

        $attrs = $context->triggerPayload['attributes'] ?? null;
        if (is_array($attrs)) {
            foreach ($attrs as $key => $value) {
                $values[(string) $key] = $value;
            }
        }

        return $values;
    }
}
