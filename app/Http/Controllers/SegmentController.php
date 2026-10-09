<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Segment;
use App\Support\Auth\Permission;
use App\Support\Marketing\SegmentAudience;
use App\Support\Marketing\SegmentInvalid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SegmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['shared', 'personal', 'all'])],
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $scope = $validated['scope'] ?? 'all';
        $seesShared = $employee->siteIdsFor(Permission::MarketingView) !== [];
        $seesPersonal = $employee->siteIdsFor(Permission::ContactView) !== [];

        if ($scope === 'shared' || ($scope === 'all' && ! $seesPersonal)) {
            Gate::authorize(Permission::MarketingView->value);
            $seesShared = true;
        } elseif ($scope === 'personal' || ($scope === 'all' && ! $seesShared)) {
            Gate::authorize(Permission::ContactView->value);
            $seesPersonal = true;
        }

        $query = Segment::query()->orderByDesc('id');

        match ($validated['status'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->active(),
        };

        $query->where(function ($visible) use ($employee, $seesShared, $seesPersonal, $scope): void {
            if ($scope !== 'personal' && $seesShared) {
                $visible->orWhere('is_shared', true);
            }
            if ($scope !== 'shared' && $seesPersonal) {
                $visible->orWhere(function ($personal) use ($employee): void {
                    $personal->where('is_shared', false)->where('created_by', $employee->id);
                });
            }
        });

        $rows = $query->get()->map(fn (Segment $segment): array => $this->payload($segment, $employee))->all();

        return $this->success($rows, 'Segments retrieved successfully.');
    }

    public function show(Request $request, Segment $segment): JsonResponse
    {
        $employee = $this->employee($request);
        if ($segment->is_shared) {
            Gate::authorize(Permission::MarketingView->value);
        } else {
            Gate::authorize(Permission::ContactView->value);
            $this->assertOwner($segment, $employee);
        }

        return $this->success($this->payload($segment, $employee, strict: true), 'Segment retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $validated = $this->validatePayload($request, creating: true);
        $shared = (bool) ($validated['is_shared'] ?? true);
        if ($shared) {
            Gate::authorize(Permission::MarketingManage->value);
        } else {
            Gate::authorize(Permission::ContactView->value);
        }

        $filter = SegmentAudience::validate($validated['filter']);

        $segment = Segment::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'entity_type' => 'contact',
            'filter' => $filter,
            'is_shared' => $shared,
            'created_by' => $employee->id,
        ]);

        return $this->created($this->payload($segment, $employee), 'Segment created successfully.');
    }

    public function update(Request $request, Segment $segment): JsonResponse
    {
        $employee = $this->employee($request);
        if (! $segment->is_shared) {
            Gate::authorize(Permission::ContactView->value);
            $this->assertOwner($segment, $employee);
        }
        $validated = $this->validatePayload($request, creating: false);

        $shared = array_key_exists('is_shared', $validated)
            ? (bool) $validated['is_shared']
            : $segment->is_shared;
        if ($shared) {
            Gate::authorize(Permission::MarketingManage->value);
        } else {
            Gate::authorize(Permission::ContactView->value);
        }

        $attributes = [];
        foreach (['name', 'description', 'is_shared'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }
        if (array_key_exists('filter', $validated)) {
            $attributes['filter'] = SegmentAudience::validate($validated['filter']);
        }
        if ($attributes !== []) {
            $segment->update($attributes);
        }

        return $this->success(
            $this->payload($segment->fresh() ?? $segment, $employee),
            'Segment updated successfully.',
        );
    }

    public function archive(Request $request, Segment $segment): JsonResponse
    {
        $employee = $this->employee($request);
        if ($segment->is_shared) {
            Gate::authorize(Permission::MarketingManage->value);
        } else {
            Gate::authorize(Permission::ContactView->value);
            $this->assertOwner($segment, $employee);
        }

        if (! $segment->isArchived()) {
            $segment->update(['archived_at' => now()]);
        }

        return $this->success(
            $this->payload($segment->fresh() ?? $segment, $employee, withCount: false),
            'Segment archived successfully.',
        );
    }

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MarketingView->value);

        $employee = $this->employee($request);
        $validated = $request->validate([
            'filter' => ['required_without:segment_id', 'prohibits:segment_id', 'array'],
            'segment_id' => ['required_without:filter', 'integer', 'exists:segments,id'],
        ]);

        if (isset($validated['segment_id'])) {
            $segment = Segment::query()->findOrFail($validated['segment_id']);
            if ($segment->is_shared) {
                Gate::authorize(Permission::MarketingView->value);
            } else {
                Gate::authorize(Permission::ContactView->value);
                $this->assertOwner($segment, $employee);
            }
            $filter = $segment->filter;
        } else {
            $filter = $validated['filter'];
        }

        return $this->success(SegmentAudience::preview($employee, $filter), 'Segment preview retrieved successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$presence, 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'entity_type' => ['sometimes', Rule::in(['contact'])],
            'filter' => [$presence, 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertOwner(Segment $segment, Employee $employee): void
    {
        if ((int) $segment->created_by !== (int) $employee->id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Segment $segment, Employee $employee, bool $strict = false, bool $withCount = true): array
    {
        $count = null;
        $invalidField = null;

        if ($withCount && ! $segment->isArchived()) {
            try {
                $count = SegmentAudience::count($employee, $segment->filter);
            } catch (SegmentInvalid $exception) {
                if ($strict) {
                    throw $exception;
                }
                $invalidField = $exception->field;
            }
        }

        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'entity_type' => $segment->entity_type,
            'filter' => $segment->filter,
            'is_shared' => $segment->is_shared,
            'created_by' => $segment->created_by,
            'archived_at' => $segment->archived_at?->toIso8601String(),
            'count' => $count,
            'invalid_field' => $invalidField,
            'created_at' => $segment->created_at?->toIso8601String(),
            'updated_at' => $segment->updated_at?->toIso8601String(),
        ];
    }

    private function employee(Request $request): Employee
    {
        $user = $request->user();
        if (! $user instanceof Employee) {
            abort(403);
        }

        return $user;
    }
}
