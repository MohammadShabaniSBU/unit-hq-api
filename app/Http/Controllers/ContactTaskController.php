<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Enums\LogChannel;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Task;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        RecordsActivity::log(LogChannel::Crm, 'task.created', $contact, [
            'task_id' => $task->id,
            'title' => $task->title,
            'due_at' => $task->due_at?->toDateString(),
        ], $request->user());

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
