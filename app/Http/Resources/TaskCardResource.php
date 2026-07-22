<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TaskCardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'priority' => $this->priority,
            'status' => $this->status,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'due_date' => $this->date($this->due_at),
            'updated_at' => $this->datetime($this->updated_at),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            'taskable' => $this->when(
                $this->relationLoaded('taskable'),
                fn () => $this->taskablePayload()
            ),
        ];
    }
}
