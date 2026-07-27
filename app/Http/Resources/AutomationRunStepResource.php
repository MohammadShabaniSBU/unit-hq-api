<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AutomationRunStepResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'node_id' => $this->node_id,
            'node_type' => $this->node_type,
            'status' => $this->status,
            'input' => $this->input,
            'output' => $this->output,
            'error' => $this->error,
            'started_at' => $this->datetime($this->started_at),
            'completed_at' => $this->datetime($this->completed_at),
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
