<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AutomationEdgeResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'automation_id'  => $this->automation_id,
            'source_node_id' => $this->source_node_id,
            'target_node_id' => $this->target_node_id,
            'source_handle'  => $this->source_handle,
            'target_handle'  => $this->target_handle,
            'label'          => $this->label,
            'condition'      => $this->condition,
            'created_at'     => $this->datetime($this->created_at),
            'updated_at'     => $this->datetime($this->updated_at),
        ];
    }
}
