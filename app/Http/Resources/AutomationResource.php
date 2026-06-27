<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AutomationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'enabled'     => $this->enabled,
            'version'     => $this->version,
            'nodes'       => AutomationNodeResource::collection($this->whenLoaded('nodes')),
            'edges'       => AutomationEdgeResource::collection($this->whenLoaded('edges')),
            'created_at'  => $this->datetime($this->created_at),
            'updated_at'  => $this->datetime($this->updated_at),
        ];
    }
}
