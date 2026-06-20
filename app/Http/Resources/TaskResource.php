<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TaskResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'priority'    => $this->priority,
            'status'      => $this->status,
            'type'        => $this->type?->value,
            'due_date'    => $this->date($this->due_at),
            'remind_at'   => $this->datetime($this->remind_at),
            'created_at'  => $this->datetime($this->created_at),
        ];
    }
}
