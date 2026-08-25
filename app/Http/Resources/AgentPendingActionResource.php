<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AgentPendingActionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_conversation_id' => $this->agent_conversation_id,
            'agent_tool_invocation_id' => $this->agent_tool_invocation_id,
            'ai_agent_id' => $this->ai_agent_id,
            'site_id' => $this->site_id,
            'tool_key' => $this->tool_key,
            'payload' => $this->payload,
            'preview' => $this->preview,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'resolved_by_employee_id' => $this->resolved_by_employee_id,
            'resolved_at' => $this->datetime($this->resolved_at),
            'rejection_reason' => $this->rejection_reason,
            'result_type' => $this->result_type,
            'result_id' => $this->result_id,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->datetime($this->expires_at),
            'created_at' => $this->datetime($this->created_at),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'key' => $this->agent->key,
                'name' => $this->agent->name,
            ]),
            'conversation' => AgentConversationResource::make($this->whenLoaded('conversation')),
            'invocation' => AgentToolInvocationResource::make($this->whenLoaded('invocation')),
            'result' => $this->whenLoaded('result'),
        ];
    }
}
