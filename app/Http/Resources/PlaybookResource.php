<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PlaybookResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'enrolment_filters' => $this->enrolment_filters ?? [],
            'automation_id' => $this->automation_id,
            'archived_at' => $this->datetime($this->archived_at),
            'active_enrolment_count' => $this->additional['active_enrolment_count'] ?? null,
            'steps' => PlaybookStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
