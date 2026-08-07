<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TaskType;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Resources\TaskCardResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    use AppliesPortalSiteFilter;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $query = Task::query()
            ->visibleTo($employee, Permission::ContactView)
            ->with(['assignee', 'taskable'])
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('updated_at');
        $this->applyPortalSiteFilter($query, $request, Task::class, Permission::ContactView);

        if ($request->filled('search')) {
            $query->search($request->string('search')->trim()->value());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Task $task) => TaskResource::make($task)),
            'Tasks retrieved successfully.'
        );
    }

    public function show(Task $task): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $task->load(['assignee', 'taskable']);

        return $this->success(
            TaskResource::make($task),
            'Task retrieved successfully.'
        );
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'type' => ['sometimes', 'nullable', Rule::enum(TaskType::class)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
        ]);

        if (array_key_exists('status', $validated)) {
            $validated['completed_at'] = $validated['status'] === 'done' ? Carbon::now() : null;
        }

        $task->update($validated);

        return $this->success(
            TaskResource::make($task->fresh()->load(['assignee', 'taskable'])),
            'Task updated successfully.'
        );
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
        ]);

        $completedAt = $validated['status'] === 'done' ? Carbon::now() : null;

        $task->update([
            'status' => $validated['status'],
            'completed_at' => $completedAt,
        ]);

        return $this->success(
            TaskCardResource::make($task->fresh()->load(['assignee', 'taskable'])),
            'Task status updated successfully.'
        );
    }
}
