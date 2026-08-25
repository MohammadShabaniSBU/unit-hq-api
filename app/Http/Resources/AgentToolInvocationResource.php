<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AgentToolInvocationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool_key' => $this->tool_key,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'result_summary' => $this->result_summary,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'denied_reason' => $this->denied_reason instanceof \BackedEnum
                ? $this->denied_reason->value
                : $this->denied_reason,
            'duration_ms' => $this->duration_ms,
            'pending_action_id' => $this->when(
                $this->relationLoaded('pendingAction'),
                fn () => $this->pendingAction?->id,
            ),
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
