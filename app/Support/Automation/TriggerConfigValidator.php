<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationNodeType;
use App\Models\Automation;
use App\Models\AutomationNode;
use Illuminate\Validation\ValidationException;

/**
 * Design-time validation for trigger configs: billing field whitelist + payment create-only.
 */
final class TriggerConfigValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function assertValid(array $nodes): void
    {
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if (! in_array($type, [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::ObjectUpdated->value,
            ], true)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $nodeKey = (string) ($node['node_key'] ?? $node['id'] ?? '');
            $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');

            if ($objectType === '') {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: trigger requires objectType.",
                ]);
            }

            if ($type === AutomationNodeType::ObjectUpdated->value && $objectType === 'payment') {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: payment supports object_created only (payments are append-only).",
                ]);
            }

            if (! TriggerableFields::supports($objectType)) {
                continue;
            }

            if ($type === AutomationNodeType::ObjectCreated->value) {
                self::assertFilterFields($config['filters'] ?? null, $objectType, $nodeKey);
            }

            if ($type === AutomationNodeType::ObjectUpdated->value) {
                $property = (string) ($config['property'] ?? '');
                if ($property !== '' && TriggerableFields::find($objectType, $property) === null) {
                    throw ValidationException::withMessages([
                        'nodes' => "Node {$nodeKey}: unknown trigger field [{$property}] for [{$objectType}].",
                    ]);
                }
            }
        }
    }

    public static function assertAutomation(Automation $automation): void
    {
        $nodes = $automation->nodes->map(fn (AutomationNode $n) => [
            'node_key' => $n->node_key,
            'type' => $n->type instanceof AutomationNodeType ? $n->type->value : (string) $n->type,
            'config' => $n->config ?? [],
        ])->all();

        self::assertValid($nodes);
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    private static function assertFilterFields(mixed $filters, string $objectType, string $nodeKey): void
    {
        if (! is_array($filters)) {
            return;
        }

        self::walkFilterTree($filters, $objectType, $nodeKey);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function walkFilterTree(array $node, string $objectType, string $nodeKey): void
    {
        if (isset($node['conditions']) && is_array($node['conditions'])) {
            foreach ($node['conditions'] as $child) {
                if (is_array($child)) {
                    self::walkFilterTree($child, $objectType, $nodeKey);
                }
            }

            return;
        }

        $field = (string) ($node['field'] ?? '');
        if ($field === '') {
            return;
        }

        if (TriggerableFields::find($objectType, $field) === null) {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: unknown trigger field [{$field}] for [{$objectType}].",
            ]);
        }
    }
}
