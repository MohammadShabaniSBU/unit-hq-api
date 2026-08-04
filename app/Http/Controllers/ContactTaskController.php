<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContactTaskController extends Controller
{
    public function store(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority'    => ['nullable', Rule::in(Task::PRIORITIES)],
            'type'        => ['nullable', Rule::enum(TaskType::class)],
            'due_at'      => ['nullable', 'date'],
        ]);

        $createdBy = $request->user()?->id ?? Employee::query()->value('id');

        if ($createdBy === null) {
            throw ValidationException::withMessages([
                'title' => ['No employee record found to attribute this task.'],
            ]);
        }

        /** @var Task $task */
        $task = $contact->tasks()->create([
            ...$validated,
            'priority'   => $validated['priority'] ?? 'medium',
            'status'     => 'open',
            'created_by' => $createdBy,
        ]);

        return $this->created(
            TaskResource::make($task),
            'Task created successfully.'
        );
    }

    public function update(Request $request, Contact $contact, Task $task): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        if ($task->taskable_type !== Contact::class || $task->taskable_id !== $contact->id) {
            return $this->notFound('Task not found.');
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
        ]);

        $completedAt = null;

        if (isset($validated['status'])) {
            $completedAt = $validated['status'] === 'done' ? Carbon::now() : null;
        }

        $task->update([
            ...$validated,
            ...($completedAt !== null || isset($validated['status']) ? ['completed_at' => $completedAt] : []),
        ]);

        return $this->success(
            TaskResource::make($task->fresh()),
            'Task updated successfully.'
        );
    }
}
