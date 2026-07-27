<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationNodeType;
use App\Models\Automation;
use App\Models\AutomationNode;

/**
 * Given a model lifecycle event + candidate automation ids, return matching (automation, trigger node) pairs.
 */
final class TriggerMatcher
{
    /**
     * @param  list<int>  $automationIds
     * @param  array<string, mixed>  $dirty  field => ['old' => …, 'new' => …]
     * @return list<array{automation: Automation, trigger: AutomationNode}>
     */
    public static function match(
        AutomationNodeType $triggerType,
        string $objectType,
        array $automationIds,
        array $dirty = [],
        ?array $modelAttributes = null,
    ): array {
        if ($automationIds === []) {
            return [];
        }

        $automations = Automation::query()
            ->with(['nodes', 'edges'])
            ->whereIn('id', $automationIds)
            ->get();

        $matches = [];

        foreach ($automations as $automation) {
            $trigger = $automation->nodes->first(
                fn (AutomationNode $node) => $node->type === $triggerType
                    && self::configObjectType($node) === $objectType,
            );

            if ($trigger === null) {
                continue;
            }

            if (! self::passesPrefilter($trigger, $triggerType, $dirty, $modelAttributes ?? [])) {
                continue;
            }

            $matches[] = ['automation' => $automation, 'trigger' => $trigger];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $dirty
     * @param  array<string, mixed>  $attributes
     */
    private static function passesPrefilter(
        AutomationNode $trigger,
        AutomationNodeType $triggerType,
        array $dirty,
        array $attributes,
    ): bool {
        $config = $trigger->config ?? [];

        if ($triggerType === AutomationNodeType::ObjectUpdated) {
            $property = (string) ($config['property'] ?? '');
            if ($property !== '' && ! array_key_exists($property, $dirty)) {
                return false;
            }

            $conditions = $config['conditions'] ?? [];
            if (! is_array($conditions)) {
                return true;
            }

            $new = $property !== '' ? ($dirty[$property]['new'] ?? null) : null;
            $old = $property !== '' ? ($dirty[$property]['old'] ?? null) : null;

            return ConditionEvaluator::matchesPropertyConditions($conditions, $new, $old);
        }

        if ($triggerType === AutomationNodeType::ObjectCreated) {
            $filters = $config['filters'] ?? null;
            if (! is_array($filters)) {
                return true;
            }

            return ConditionEvaluator::matchesGroup($filters, $attributes);
        }

        return true;
    }

    private static function configObjectType(AutomationNode $node): ?string
    {
        $config = $node->config ?? [];

        $type = $config['objectType'] ?? $config['object_type'] ?? null;

        return is_string($type) ? $type : null;
    }
}
