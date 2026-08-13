<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AttributeEntityType;
use App\Enums\LogChannel;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Task;
use App\Support\Auth\SubjectSite;
use App\Support\RecordsActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateTask implements Tool, Approvable
{
    use InteractsWithApprovals;

    private const TYPE_MAP = [
        'contact' => Contact::class,
        'deal' => Deal::class,
    ];

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Add a task to a contact or deal.';
    }

    public function handle(Request $request): Stringable|string
    {
        $type = $request['taskable_type'] ?? null;

        if (! isset(self::TYPE_MAP[$type])) {
            return json_encode([
                'success' => false,
                'error' => "Unsupported taskable_type '{$type}'.",
            ]);
        }

        $modelClass = self::TYPE_MAP[$type];
        $taskable = $modelClass::query()->find($request['taskable_id']);

        if ($taskable === null) {
            return json_encode([
                'success' => false,
                'error' => "No {$type} found with that ID.",
            ]);
        }

        $permission = AttributeEntityType::from($type)->managePermission();

        if (! $this->employee->allowsPermission($permission, SubjectSite::for($taskable))) {
            return json_encode([
                'success' => false,
                'error' => "You do not have permission to add a task to this {$type}.",
            ]);
        }

        $task = $taskable->tasks()->create([
            'title' => $request['title'],
            'description' => $request['description'] ?? null,
            'priority' => $request['priority'] ?? 'medium',
            'type' => $request['type'] ?? null,
            'due_at' => $request['due_at'] ?? null,
            'status' => 'open',
            'created_by' => $this->employee->id,
        ]);

        RecordsActivity::log(LogChannel::Crm, 'task.created', $taskable, [
            'task_id' => $task->id,
            'title' => $task->title,
            'due_at' => $task->due_at?->toDateString(),
        ], $this->employee);

        return json_encode([
            'success' => true,
            'message' => 'Task created successfully.',
            'task_id' => $task->id,
            'taskable_type' => $type,
            'taskable_id' => $taskable->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'taskable_type' => $schema->string()
                ->description('Type of record to attach the task to')
                ->enum(array_keys(self::TYPE_MAP))
                ->required(),
            'taskable_id' => $schema->integer()
                ->description('ID of the record to attach the task to')
                ->required(),
            'title' => $schema->string()
                ->description('Task title')
                ->required(),
            'description' => $schema->string()
                ->description('Task description')
                ->nullable(),
            'priority' => $schema->string()
                ->description('Task priority')
                ->enum(Task::PRIORITIES)
                ->nullable(),
            'type' => $schema->string()
                ->description('Task type')
                ->enum(array_map(fn (TaskType $type) => $type->value, TaskType::cases()))
                ->nullable(),
            'due_at' => $schema->string()
                ->description('Due date (YYYY-MM-DD format)')
                ->nullable(),
        ];
    }
}
