<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * Design-time validation for action.update_object targetRecord configs.
 */
final class TargetRecordValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    public static function assertValid(array $nodes, array $edges): void
    {
        $byKey = [];
        foreach ($nodes as $node) {
            $key = (string) ($node['node_key'] ?? $node['id'] ?? '');
            if ($key === '') {
                continue;
            }
            $byKey[$key] = $node;
        }

        $incoming = self::buildIncomingMap($edges);

        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($type !== 'action.update_object') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');
            $target = TokenResolver::normalizeTargetRecordConfig($config);
            $nodeKey = (string) ($node['node_key'] ?? $node['id'] ?? '');

            match ((string) ($target['mode'] ?? '')) {
                'trigger_subject' => self::assertTriggerSubject($nodes, $objectType, $nodeKey),
                'step_output' => self::assertStepOutput($byKey, $incoming, $nodeKey, $objectType, $target),
                'static' => self::assertStatic($target, $objectType !== '' ? $objectType : (string) ($target['objectType'] ?? '')),
                'expression' => self::assertExpression($byKey, $incoming, $nodeKey, (string) ($target['template'] ?? '')),
                default => throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: targetRecord.mode is required.",
                ]),
            };
        }
    }

    /**
     * Normalize update_object configs in-place before persist.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeNodes(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? '') !== 'action.update_object') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $target = TokenResolver::normalizeTargetRecordConfig($config);

            // Persist a clean camelCase targetRecord; drop legacy keys.
            unset(
                $config['targetSource'],
                $config['target_source'],
                $config['staticId'],
                $config['dynamicExpression'],
                $config['targetId'],
                $config['target_id'],
                $config['target_record'],
            );

            $clean = ['mode' => $target['mode']];
            if ($target['mode'] === 'step_output') {
                $clean['nodeKey'] = $target['nodeKey'];
                $clean['field'] = $target['field'] ?? 'subject_id';
            } elseif ($target['mode'] === 'static') {
                $clean['objectType'] = $target['objectType'] ?? ($config['objectType'] ?? $config['object_type'] ?? null);
                $clean['id'] = isset($target['id']) ? (int) $target['id'] : null;
            } elseif ($target['mode'] === 'expression') {
                $clean['template'] = $target['template'] ?? '';
            }

            $config['targetRecord'] = $clean;
            $nodes[$i]['config'] = $config;
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<string, list<string>>  targetKey => list of sourceKeys
     */
    private static function buildIncomingMap(array $edges): array
    {
        $incoming = [];
        foreach ($edges as $edge) {
            $source = (string) ($edge['source_node_id'] ?? '');
            $target = (string) ($edge['target_node_id'] ?? '');
            if ($source === '' || $target === '') {
                continue;
            }
            $incoming[$target] ??= [];
            $incoming[$target][] = $source;
        }

        return $incoming;
    }

    /**
     * @param  array<string, list<string>>  $incoming
     * @return list<string>
     */
    public static function reachableUpstream(string $fromKey, array $incoming): array
    {
        $visited = [];
        $queue = [$fromKey];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($incoming[$current] ?? [] as $parent) {
                if (isset($visited[$parent])) {
                    continue;
                }
                $visited[$parent] = true;
                $queue[] = $parent;
            }
        }

        return array_keys($visited);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private static function assertTriggerSubject(array $nodes, string $objectType, string $nodeKey): void
    {
        $triggerObjectType = null;
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if (! str_starts_with($type, 'trigger.')) {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $triggerObjectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');
            break;
        }

        if ($objectType === '' || $triggerObjectType === '' || $triggerObjectType !== $objectType) {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: trigger_subject requires a trigger with matching objectType ({$objectType}).",
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  array<string, list<string>>  $incoming
     * @param  array<string, mixed>  $target
     */
    private static function assertStepOutput(
        array $byKey,
        array $incoming,
        string $nodeKey,
        string $objectType,
        array $target,
    ): void {
        $refKey = (string) ($target['nodeKey'] ?? '');
        $field = (string) ($target['field'] ?? 'subject_id');

        if ($refKey === '' || ! isset($byKey[$refKey])) {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: step_output references unknown node_key {$refKey}.",
            ]);
        }

        $upstream = self::reachableUpstream($nodeKey, $incoming);
        if (! in_array($refKey, $upstream, true)) {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: step_output node_key {$refKey} is not reachable upstream.",
            ]);
        }

        $refNode = $byKey[$refKey];
        $outputs = self::idOutputsForNode($refNode);
        $match = false;
        foreach ($outputs as $out) {
            if ($out['field'] === $field && $out['objectType'] === $objectType) {
                $match = true;
                break;
            }
        }

        if (! $match) {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: step_output field {$field} is not an id output for objectType {$objectType}.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function assertStatic(array $target, string $objectType): void
    {
        $id = $target['id'] ?? null;
        $type = (string) ($target['objectType'] ?? $objectType);

        if ($type === '' || $id === null || $id === '') {
            throw ValidationException::withMessages([
                'nodes' => 'static targetRecord requires objectType and id.',
            ]);
        }

        $class = Relation::getMorphedModel($type);
        if ($class === null || ! is_a($class, Model::class, true)) {
            throw ValidationException::withMessages([
                'nodes' => "static targetRecord unknown objectType {$type}.",
            ]);
        }

        if (! $class::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'nodes' => "static targetRecord {$type}#{$id} does not exist.",
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  array<string, list<string>>  $incoming
     */
    private static function assertExpression(
        array $byKey,
        array $incoming,
        string $nodeKey,
        string $template,
    ): void {
        if (trim($template) === '') {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: expression targetRecord requires a template.",
            ]);
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $template, $matches);
        $paths = $matches[1] ?? [];
        if ($paths === []) {
            return;
        }

        $upstream = self::reachableUpstream($nodeKey, $incoming);

        foreach ($paths as $path) {
            if (! str_starts_with($path, 'steps.')) {
                continue;
            }
            $parts = explode('.', $path);
            $refKey = $parts[1] ?? '';
            if ($refKey === '' || ! isset($byKey[$refKey])) {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: expression references unknown node_key {$refKey}.",
                ]);
            }
            if (! in_array($refKey, $upstream, true)) {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: expression node_key {$refKey} is not reachable upstream.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{field: string, objectType: string}>
     */
    public static function idOutputsForNode(array $node): array
    {
        $type = (string) ($node['type'] ?? '');
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');

        if ($objectType === '') {
            return [];
        }

        if (
            $type === 'trigger.object_created'
            || $type === 'trigger.object_updated'
            || $type === 'action.update_object'
            || $type === 'action.create_object'
        ) {
            return [['field' => 'subject_id', 'objectType' => $objectType]];
        }

        return [];
    }
}
