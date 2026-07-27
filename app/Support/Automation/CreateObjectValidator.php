<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Illuminate\Validation\ValidationException;

/**
 * Design-time validation for action.create_object configs.
 */
final class CreateObjectValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function assertValid(array $nodes): void
    {
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($type !== 'action.create_object') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $nodeKey = (string) ($node['node_key'] ?? $node['id'] ?? '');
            $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');

            if ($objectType === '') {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: create_object requires objectType.",
                ]);
            }

            if (! CreateObjectAllowlist::contains($objectType)) {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: create_object only supports ".CreateObjectAllowlist::supportedList().".",
                ]);
            }

            $fields = $config['fields'] ?? $config['updates'] ?? [];
            if ($fields !== null && ! is_array($fields)) {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: create_object fields must be an array.",
                ]);
            }
        }
    }

    /**
     * Normalize create_object configs in-place before persist.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeNodes(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? '') !== 'action.create_object') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');
            $fields = $config['fields'] ?? $config['updates'] ?? [];
            if (! is_array($fields)) {
                $fields = [];
            }

            unset($config['updates'], $config['object_type'], $config['related_to']);

            $config['objectType'] = $objectType;
            $config['fields'] = array_values(array_filter(
                $fields,
                static fn ($field) => is_array($field),
            ));

            if ($objectType === 'task' || $objectType === 'note') {
                $related = $config['relatedTo'] ?? $config['related_to'] ?? null;
                if (! is_array($related) || ! isset($related['mode'])) {
                    $related = ['mode' => 'trigger_subject'];
                }
                $normalized = TokenResolver::normalizeTargetRecordConfig(['targetRecord' => $related]);
                $clean = ['mode' => $normalized['mode']];
                if ($normalized['mode'] === 'step_output') {
                    $clean['nodeKey'] = $normalized['nodeKey'];
                    $clean['field'] = $normalized['field'] ?? 'subject_id';
                } elseif ($normalized['mode'] === 'static') {
                    $clean['objectType'] = $normalized['objectType'] ?? null;
                    $clean['id'] = isset($normalized['id']) ? (int) $normalized['id'] : null;
                } elseif ($normalized['mode'] === 'expression') {
                    $clean['template'] = $normalized['template'] ?? '';
                }
                $config['relatedTo'] = $clean;
            } else {
                unset($config['relatedTo']);
            }

            $nodes[$i]['config'] = $config;
        }

        return $nodes;
    }
}
