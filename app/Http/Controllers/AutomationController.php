<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Http\Resources\AutomationResource;
use App\Http\Resources\AutomationRunResource;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\Deal;
use App\Models\Employee;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Automation\CreateObjectValidator;
use App\Support\Automation\RunLifecycle;
use App\Support\Automation\TargetRecordValidator;
use App\Support\Automation\TriggerableFields;
use App\Support\Automation\TriggerConfigValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'inactive', 'archived', 'all'])],
        ]);

        $query = Automation::query()
            ->with(['nodes', 'edges.sourceNode', 'edges.targetNode'])
            ->withCount([
                'runs',
                'runs as successful_runs_count' => fn ($q) => $q->where('status', 'succeeded'),
                'runs as failed_runs_count' => fn ($q) => $q->where('status', 'failed'),
            ])
            ->latest();

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%'.trim($validated['search']).'%');
        }

        $status = $validated['status'] ?? 'all';

        match ($status) {
            'archived' => $query->archived(),
            'draft', 'active', 'inactive' => $query->active()->where('status', $status),
            default => $query->active(), // all = non-archived
        };

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (Automation $automation) => AutomationResource::make($automation),
            ),
            'Automations retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(AutomationStatus::class)],
            'nodes' => ['nullable', 'array'],
            'edges' => ['nullable', 'array'],
        ]);

        $automation = DB::transaction(function () use ($validated) {
            $automation = Automation::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? AutomationStatus::Draft,
            ]);

            $nodes = TargetRecordValidator::normalizeNodes($validated['nodes'] ?? []);
            $nodes = CreateObjectValidator::normalizeNodes($nodes);
            $edges = $validated['edges'] ?? [];
            TargetRecordValidator::assertValid($nodes, $edges);
            CreateObjectValidator::assertValid($nodes);
            TriggerConfigValidator::assertValid($nodes);
            $this->syncNodes($automation, $nodes);
            $this->syncEdges($automation, $edges);

            return $automation;
        });

        AutomationWatchCache::flushAll();

        return $this->created(
            AutomationResource::make($automation->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation created successfully.',
        );
    }

    public function show(Automation $automation): JsonResponse
    {
        return $this->success(
            AutomationResource::make($automation->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation retrieved successfully.',
        );
    }

    public function update(Request $request, Automation $automation): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::enum(AutomationStatus::class)],
            'nodes' => ['sometimes', 'nullable', 'array'],
            'edges' => ['sometimes', 'nullable', 'array'],
        ]);

        if (
            isset($validated['status'])
            && AutomationStatus::tryFrom(
                $validated['status'] instanceof AutomationStatus
                    ? $validated['status']->value
                    : (string) $validated['status'],
            ) === AutomationStatus::Active
        ) {
            $this->assertCanActivate($automation, $validated['nodes'] ?? null);
            if (! array_key_exists('nodes', $validated)) {
                $automation->loadMissing('nodes');
                TriggerConfigValidator::assertAutomation($automation);
            }
        }

        DB::transaction(function () use ($automation, $validated): void {
            $fields = [];
            if (array_key_exists('name', $validated)) {
                $fields['name'] = $validated['name'];
            }
            if (array_key_exists('description', $validated)) {
                $fields['description'] = $validated['description'];
            }
            if (array_key_exists('status', $validated)) {
                $fields['status'] = $validated['status'];
            }

            if ($fields !== []) {
                $automation->update($fields);
            }

            if (array_key_exists('nodes', $validated) || array_key_exists('edges', $validated)) {
                $automation->loadMissing(['nodes', 'edges.sourceNode', 'edges.targetNode']);

                $nodes = array_key_exists('nodes', $validated)
                    ? ($validated['nodes'] ?? [])
                    : $automation->nodes->map(fn (AutomationNode $n) => [
                        'node_key' => $n->node_key,
                        'kind' => $n->kind,
                        'type' => $n->type instanceof AutomationNodeType ? $n->type->value : (string) $n->type,
                        'label' => $n->label,
                        'description' => $n->description,
                        'position_x' => $n->position_x,
                        'position_y' => $n->position_y,
                        'config' => $n->config ?? [],
                        'metadata' => $n->metadata,
                    ])->all();

                $edges = array_key_exists('edges', $validated)
                    ? ($validated['edges'] ?? [])
                    : $automation->edges->map(fn (AutomationEdge $e) => [
                        'source_node_id' => $e->sourceNode?->node_key,
                        'target_node_id' => $e->targetNode?->node_key,
                        'source_handle' => $e->source_handle,
                        'target_handle' => $e->target_handle,
                        'label' => $e->label,
                        'condition' => $e->condition,
                    ])->all();

                $nodes = TargetRecordValidator::normalizeNodes($nodes);
                $nodes = CreateObjectValidator::normalizeNodes($nodes);
                TargetRecordValidator::assertValid($nodes, $edges);
                CreateObjectValidator::assertValid($nodes);
                TriggerConfigValidator::assertValid($nodes);

                if (array_key_exists('nodes', $validated)) {
                    $this->syncNodes($automation, $nodes);
                }
                if (array_key_exists('edges', $validated)) {
                    $this->syncEdges($automation, $edges);
                }
            }
        });

        AutomationWatchCache::flushAll();

        return $this->success(
            AutomationResource::make($automation->fresh()->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation updated successfully.',
        );
    }

    public function destroy(Automation $automation): JsonResponse
    {
        if (! $automation->isArchived()) {
            $automation->update(['archived_at' => now(), 'status' => AutomationStatus::Inactive]);
            AutomationWatchCache::flushAll();
        }

        return $this->noContent('Automation archived successfully.');
    }

    public function archive(Automation $automation): JsonResponse
    {
        if ($automation->isArchived()) {
            return $this->success(
                AutomationResource::make($automation->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
                'Automation is already archived.',
            );
        }

        $automation->update([
            'archived_at' => now(),
            'status' => AutomationStatus::Inactive,
        ]);
        AutomationWatchCache::flushAll();

        return $this->success(
            AutomationResource::make($automation->fresh()->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation archived successfully.',
        );
    }

    public function unarchive(Automation $automation): JsonResponse
    {
        if (! $automation->isArchived()) {
            return $this->success(
                AutomationResource::make($automation->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
                'Automation is already active.',
            );
        }

        $automation->update(['archived_at' => null]);
        AutomationWatchCache::flushAll();

        return $this->success(
            AutomationResource::make($automation->fresh()->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation unarchived successfully.',
        );
    }

    public function activate(Automation $automation): JsonResponse
    {
        if ($automation->isArchived()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot activate an archived automation. Unarchive it first.',
            ]);
        }

        $automation->loadMissing('nodes');
        $this->assertCanActivate($automation);
        TriggerConfigValidator::assertAutomation($automation);

        $automation->update(['status' => AutomationStatus::Active]);
        AutomationWatchCache::flushAll();

        return $this->success(
            AutomationResource::make($automation->fresh()->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation activated successfully.',
        );
    }

    public function triggerFields(string $objectType): JsonResponse
    {
        if (! TriggerableFields::supports($objectType)) {
            throw ValidationException::withMessages([
                'objectType' => "Unknown trigger object type [{$objectType}].",
            ]);
        }

        return $this->success(
            TriggerableFields::schema($objectType),
            'Trigger fields retrieved successfully.',
        );
    }

    public function deactivate(Automation $automation): JsonResponse
    {
        $automation->update(['status' => AutomationStatus::Inactive]);
        AutomationWatchCache::flushAll();

        return $this->success(
            AutomationResource::make($automation->fresh()->load(['nodes', 'edges.sourceNode', 'edges.targetNode'])),
            'Automation deactivated successfully.',
        );
    }

    public function runs(Request $request, Automation $automation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(AutomationRunStatus::class)],
            'subject_type' => ['nullable', 'string'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $automation->runs()
            ->with([
                'subject' => fn ($morphTo) => $morphTo->morphWith([
                    Deal::class => ['contact'],
                ]),
                'causer',
                'triggerNode',
            ])
            ->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['subject_type'])) {
            $query->where('subject_type', $validated['subject_type']);
        }
        if (array_key_exists('subject_id', $validated) && $validated['subject_id'] !== null) {
            $query->where('subject_id', $validated['subject_id']);
        }
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (AutomationRun $run) => AutomationRunResource::make($run),
            ),
            'Automation runs retrieved successfully.',
        );
    }

    public function showRun(Automation $automation, AutomationRun $run): JsonResponse
    {
        if ((int) $run->automation_id !== (int) $automation->id) {
            return $this->notFound('Automation run not found.');
        }

        $run->load([
            'steps',
            'subject' => fn ($morphTo) => $morphTo->morphWith([
                Deal::class => ['contact'],
            ]),
            'causer',
            'triggerNode',
        ]);

        return $this->success(
            AutomationRunResource::make($run),
            'Automation run retrieved successfully.',
        );
    }

    public function cancelRun(Request $request, AutomationRun $run): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        RunLifecycle::cancel($run, AutomationCancelCause::Manual, $employee);

        $run->load([
            'steps',
            'subject' => fn ($morphTo) => $morphTo->morphWith([
                Deal::class => ['contact'],
            ]),
            'causer',
            'triggerNode',
            'cancelledBy',
        ]);

        return $this->success(
            AutomationRunResource::make($run),
            'Automation run cancelled successfully.',
        );
    }

    /** @param  array<int, array<string, mixed>>|null  $incomingNodes */
    private function assertCanActivate(Automation $automation, ?array $incomingNodes = null): void
    {
        $types = [];

        if ($incomingNodes !== null) {
            foreach ($incomingNodes as $node) {
                $types[] = $node['type'] ?? null;
            }
        } else {
            $types = $automation->nodes()->pluck('type')->all();
        }

        foreach ($types as $type) {
            $value = $type instanceof AutomationNodeType ? $type->value : (string) $type;
            if ($value === AutomationNodeType::EmailReceived->value) {
                throw ValidationException::withMessages([
                    'status' => 'Inbound email triggers are not yet supported. Remove trigger.email_received nodes before activating.',
                ]);
            }
        }
    }

    /** @param  array<int, array<string, mixed>>  $nodes */
    private function syncNodes(Automation $automation, array $nodes): void
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
            'label' => $node['label'],
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
    private function syncEdges(Automation $automation, array $edges): void
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
