<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class NoteResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'content'    => $this->content,
            'created_at' => $this->datetime($this->created_at),
            'employee'   => $this->whenLoaded('employee', fn () => [
                'id'   => $this->employee->id,
                'name' => $this->employee->name,
            ]),
        ];
    }
}
