<?php

namespace App\Http\Controllers;

use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Automation::query()->with(['nodes', 'edges'])->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Automation $automation) => AutomationResource::make($automation)),
            'Automations retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'enabled'     => ['boolean'],
            'nodes'       => ['nullable', 'array'],
            'edges'       => ['nullable', 'array'],
        ]);

        $automation = Automation::query()->create([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'enabled'     => $validated['enabled'] ?? false,
        ]);

        $this->syncNodes($automation, $validated['nodes'] ?? []);
        $this->syncEdges($automation, $validated['edges'] ?? []);

        return $this->created(
            AutomationResource::make($automation->load(['nodes', 'edges'])),
            'Automation created successfully.'
        );
    }

    public function show(Automation $automation): JsonResponse
    {
        return $this->success(
            AutomationResource::make($automation->load(['nodes', 'edges'])),
            'Automation retrieved successfully.'
        );
    }

    public function update(Request $request, Automation $automation): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'enabled'     => ['sometimes', 'boolean'],
            'nodes'       => ['sometimes', 'nullable', 'array'],
            'edges'       => ['sometimes', 'nullable', 'array'],
        ]);

        $fields = [];
        if (array_key_exists('name', $validated)) $fields['name'] = $validated['name'];
        if (array_key_exists('description', $validated)) $fields['description'] = $validated['description'];
        if (array_key_exists('enabled', $validated)) $fields['enabled'] = $validated['enabled'];

        if (! empty($fields)) {
            $automation->update($fields);
        }

        if (array_key_exists('nodes', $validated)) {
            $this->syncNodes($automation, $validated['nodes'] ?? []);
        }

        if (array_key_exists('edges', $validated)) {
            $this->syncEdges($automation, $validated['edges'] ?? []);
        }

        return $this->success(
            AutomationResource::make($automation->fresh()->load(['nodes', 'edges'])),
            'Automation updated successfully.'
        );
    }

    public function destroy(Automation $automation): JsonResponse
    {
        $automation->delete();

        return $this->noContent('Automation deleted successfully.');
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function syncNodes(Automation $automation, array $nodes): void
    {
        $automation->nodes()->delete();

        if (empty($nodes)) {
            return;
        }

        $records = array_map(fn (array $node) => [
            'automation_id' => $automation->id,
            'node_key'      => $node['node_key'] ?? $node['id'] ?? uniqid('node_'),
            'kind'          => $node['kind'],
            'type'          => $node['type'],
            'label'         => $node['label'],
            'description'   => $node['description'] ?? null,
            'position_x'    => (int) ($node['position_x'] ?? 0),
            'position_y'    => (int) ($node['position_y'] ?? 0),
            'config'        => json_encode($node['config'] ?? []),
            'metadata'      => isset($node['metadata']) ? json_encode($node['metadata']) : null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $nodes);

        AutomationNode::insert($records);
    }

    /** @param array<int, array<string, mixed>> $edges */
    private function syncEdges(Automation $automation, array $edges): void
    {
        $automation->edges()->delete();

        if (empty($edges)) {
            return;
        }

        // Build a node_key → id map for resolving edge references
        $nodeMap = $automation->nodes()
            ->pluck('id', 'node_key')
            ->all();

        $records = [];
        foreach ($edges as $edge) {
            $sourceId = $nodeMap[$edge['source_node_id']] ?? null;
            $targetId = $nodeMap[$edge['target_node_id']] ?? null;

            if ($sourceId === null || $targetId === null) {
                continue;
            }

            $records[] = [
                'automation_id'  => $automation->id,
                'source_node_id' => $sourceId,
                'target_node_id' => $targetId,
                'source_handle'  => $edge['source_handle'] ?? null,
                'target_handle'  => $edge['target_handle'] ?? null,
                'label'          => $edge['label'] ?? null,
                'condition'      => json_encode($edge['condition'] ?? ['type' => 'always']),
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
        }

        if (! empty($records)) {
            AutomationEdge::insert($records);
        }
    }
}
