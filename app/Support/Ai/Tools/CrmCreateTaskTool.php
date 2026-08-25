<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\Task;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;

final class CrmCreateTaskTool implements AgentTool
{
    public function key(): string
    {
        return 'crm.create_task';
    }

    public function description(): string
    {
        return 'Create a follow-up task on a contact or deal using the related_to parent-morph shape.';
    }

    public function schema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Task title',
            ],
            'related_to_type' => [
                'type' => 'string',
                'required' => true,
                'enum' => AllowlistedParent::TYPES,
                'description' => 'Parent morph alias: contact or deal',
            ],
            'related_to_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Parent id',
            ],
            'description' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional task body',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $employeeId = AgentWriteAttribution::requireEmployeeId($ctx);
        if ($employeeId instanceof ToolResult) {
            return $employeeId;
        }

        $parent = AllowlistedParent::resolve(
            (string) $arguments['related_to_type'],
            (int) $arguments['related_to_id'],
            $principal,
        );
        if ($parent instanceof ToolResult) {
            return $parent;
        }

        $task = Task::query()->create([
            'taskable_type' => $parent->getMorphClass(),
            'taskable_id' => $parent->getKey(),
            'title' => (string) $arguments['title'],
            'description' => isset($arguments['description']) ? (string) $arguments['description'] : null,
            'priority' => 'medium',
            'status' => 'open',
            'created_by' => $employeeId,
        ]);

        AgentWriteAttribution::log(LogChannel::Crm, 'task.created', $parent, $ctx, [
            'task_id' => $task->id,
        ]);

        return ToolResult::ok(
            [
                'task_id' => $task->id,
                'related_to_type' => $parent->getMorphClass(),
                'related_to_id' => $parent->getKey(),
            ],
            "Created task {$task->id}: {$task->title}.",
            (new FactBag)->identifier((string) $task->id)->number($task->id),
            resultType: 'task',
            resultId: $task->id,
            entities: [
                EntityRef::task($task),
                EntityRef::of(
                    EntityType::from($parent->getMorphClass()),
                    (int) $parent->getKey(),
                    $parent instanceof Contact
                        ? trim($parent->first_name.' '.$parent->last_name)
                        : 'deal '.$parent->getKey(),
                ),
            ],
        );
    }
}
