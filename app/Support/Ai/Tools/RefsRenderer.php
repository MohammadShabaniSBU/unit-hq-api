<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\Enums\EntityType;

/**
 * Renders the model-facing id line appended to a tool result.
 *
 * Shared by ToolResult::modelText(), the prior-turn rehydration in
 * AgentRuntime::buildMessages(), and the rolling summary in ContextWindow, so
 * the ordering rule cannot drift between them.
 */
final class RefsRenderer
{
    /**
     * @param  list<EntityRef>  $refs
     */
    public static function render(array $refs, string $heading = 'Refs'): string
    {
        if ($refs === []) {
            return '';
        }

        $typeOrder = [];
        foreach (EntityType::cases() as $position => $case) {
            $typeOrder[$case->value] = $position;
        }

        $unique = [];
        foreach ($refs as $ref) {
            $key = $ref->type->value.':'.$ref->id;
            if (! isset($unique[$key])) {
                $unique[$key] = $ref;
            }
        }

        $sorted = array_values($unique);
        usort($sorted, static function (EntityRef $a, EntityRef $b) use ($typeOrder): int {
            $typeA = $typeOrder[$a->type->value] ?? PHP_INT_MAX;
            $typeB = $typeOrder[$b->type->value] ?? PHP_INT_MAX;

            return $typeA !== $typeB
                ? $typeA <=> $typeB
                : $a->id <=> $b->id;
        });

        $entries = array_map(
            static fn (EntityRef $ref): string => "{$ref->type->value} {$ref->id} = {$ref->label}",
            $sorted,
        );

        return $heading.': '.implode('; ', $entries);
    }
}
