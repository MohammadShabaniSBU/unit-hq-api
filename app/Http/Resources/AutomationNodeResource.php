<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AutomationNodeResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'automation_id' => $this->automation_id,
            'node_key'     => $this->node_key,
            'kind'         => $this->kind,
            'type'         => $this->type,
            'label'        => $this->label,
            'description'  => $this->description,
            'position_x'   => $this->position_x,
            'position_y'   => $this->position_y,
            'config'       => $this->config,
            'metadata'     => $this->metadata,
            'created_at'   => $this->datetime($this->created_at),
            'updated_at'   => $this->datetime($this->updated_at),
        ];
    }
}
