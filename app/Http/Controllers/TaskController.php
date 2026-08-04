<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
