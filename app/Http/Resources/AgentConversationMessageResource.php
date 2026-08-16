<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AgentConversationMessageResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'role' => $this->role instanceof \BackedEnum ? $this->role->value : $this->role,
            'content' => $this->content,
            'tool_calls' => $this->tool_calls,
            'tool_call_id' => $this->tool_call_id,
            'model' => $this->model,
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'latency_ms' => $this->latency_ms,
            'finish_reason' => $this->finish_reason,
            'blocked_by' => $this->blocked_by,
            'fact_keys' => $this->fact_keys,
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
