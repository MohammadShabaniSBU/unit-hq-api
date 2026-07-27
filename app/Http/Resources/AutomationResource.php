<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AutomationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'version' => $this->version,
            'archived_at' => $this->datetime($this->archived_at),
            'runs_count' => $this->when(isset($this->runs_count), (int) $this->runs_count),
            'successful_runs_count' => $this->when(
                isset($this->successful_runs_count),
                (int) $this->successful_runs_count,
            ),
            'failed_runs_count' => $this->when(
                isset($this->failed_runs_count),
                (int) $this->failed_runs_count,
            ),
            'nodes' => AutomationNodeResource::collection($this->whenLoaded('nodes')),
            'edges' => AutomationEdgeResource::collection($this->whenLoaded('edges')),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
