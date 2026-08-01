<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Automation\CreateObjectValidator;
use App\Support\Automation\TargetRecordValidator;
use App\Support\Automation\TriggerConfigValidator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Loads panel-shaped automation graph fixtures through the same validators as
 * AutomationController bulk save.
 */
final class AutomationFixtureLoader
{
    /**
     * @return array{
     *     automation: Automation,
     *     payload: array<string, mixed>,
     *     harness: array<string, mixed>
     * }
     */
    public static function load(string $name): array
    {
        $path = self::fixturePath($name.'.json');
        if (! is_file($path)) {
            throw new InvalidArgumentException("Automation fixture [{$name}] not found at {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Automation fixture [{$name}] is not valid JSON object");
        }

        /** @var array<string, mixed> $harness */
        $harness = is_array($decoded['_harness'] ?? null) ? $decoded['_harness'] : [];
        unset($decoded['_harness']);

        $nodes = is_array($decoded['nodes'] ?? null) ? $decoded['nodes'] : [];
        $edges = is_array($decoded['edges'] ?? null) ? $decoded['edges'] : [];

        $nodes = TargetRecordValidator::normalizeNodes($nodes);
        $nodes = CreateObjectValidator::normalizeNodes($nodes);
        TargetRecordValidator::assertValid($nodes, $edges);
        CreateObjectValidator::assertValid($nodes);
        TriggerConfigValidator::assertValid($nodes);

        $automation = Automation::query()->create([
            'name' => (string) ($decoded['name'] ?? $name),
            'description' => $decoded['description'] ?? null,
            'status' => AutomationStatus::tryFrom((string) ($decoded['status'] ?? 'active'))
                ?? AutomationStatus::Active,
            'version' => 1,
        ]);

        self::syncNodes($automation, $nodes);
        self::syncEdges($automation, $edges);
        AutomationWatchCache::flushAll();

        return [
            'automation' => $automation->fresh(['nodes', 'edges']) ?? $automation,
            'payload' => $decoded,
            'harness' => $harness,
        ];
    }

    /**
     * Collect node types used across all committed fixtures (for HandlerCoverageTest).
     *
     * @return list<string>
     */
    public static function allFixtureNodeTypes(): array
    {
        $dir = self::fixturePath();
        if (! is_dir($dir)) {
            return [];
        }

        $types = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded) || ! is_array($decoded['nodes'] ?? null)) {
                continue;
            }
            foreach ($decoded['nodes'] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $type = (string) ($node['type'] ?? '');
                if ($type !== '') {
                    $types[$type] = true;
                }
            }
        }

        return array_keys($types);
    }

    private static function fixturePath(string $file = ''): string
    {
        $dir = dirname(__DIR__).'/fixtures/automations';

        return $file === '' ? $dir : $dir.'/'.$file;
    }

    /** @param  array<int, array<string, mixed>>  $nodes */
    private static function syncNodes(Automation $automation, array $nodes): void
    {
        $automation->nodes()->delete();

        if ($nodes === []) {
            return;
        }

        $records = array_map(fn (array $node) => [
            'automation_id' => $automation->id,
            'node_key' => $node['node_key'] ?? $node['id'] ?? uniqid('node_'),
            'kind' => $node['kind'],
            'type' => $node['type'],
            'label' => $node['label'] ?? ($node['node_key'] ?? 'node'),
            'description' => $node['description'] ?? null,
            'position_x' => (int) ($node['position_x'] ?? 0),
            'position_y' => (int) ($node['position_y'] ?? 0),
            'config' => json_encode($node['config'] ?? []),
            'metadata' => isset($node['metadata']) ? json_encode($node['metadata']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $nodes);

        AutomationNode::insert($records);
    }

    /** @param  array<int, array<string, mixed>>  $edges */
    private static function syncEdges(Automation $automation, array $edges): void
    {
        $automation->edges()->delete();

        if ($edges === []) {
            return;
        }

        $nodeMap = $automation->nodes()
            ->pluck('id', 'node_key')
            ->all();

        $records = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $sourceId = $nodeMap[$edge['source_node_id']] ?? null;
            $targetId = $nodeMap[$edge['target_node_id']] ?? null;

            if ($sourceId === null || $targetId === null) {
                continue;
            }

            $handle = $edge['source_handle'] ?? null;
            if ($handle === null || $handle === '') {
                $handle = 'default';
            }

            $records[] = [
                'automation_id' => $automation->id,
                'source_node_id' => $sourceId,
                'target_node_id' => $targetId,
                'source_handle' => $handle,
                'target_handle' => $edge['target_handle'] ?? null,
                'label' => $edge['label'] ?? null,
                'condition' => json_encode($edge['condition'] ?? ['type' => 'always']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($records !== []) {
            AutomationEdge::insert($records);
        }
    }
}
