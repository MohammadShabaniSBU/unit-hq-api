<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AgentConversationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_agent_id' => $this->ai_agent_id,
            'agent_key' => $this->whenLoaded('aiAgent', fn () => $this->aiAgent?->key),
            'audience' => $this->audience instanceof \BackedEnum ? $this->audience->value : $this->audience,
            'origin' => $this->origin instanceof \BackedEnum ? $this->origin->value : $this->origin,
            'channel' => $this->channel instanceof \BackedEnum ? $this->channel->value : $this->channel,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', function () {
                if ($this->contact === null) {
                    return null;
                }

                return [
                    'id' => $this->contact->id,
                    'first_name' => $this->contact->first_name,
                    'last_name' => $this->contact->last_name,
                ];
            }),
            'site_id' => $this->site_id,
            'verification_level' => $this->verification_level instanceof \BackedEnum
                ? $this->verification_level->value
                : $this->verification_level,
            'state' => $this->state instanceof \BackedEnum ? $this->state->value : $this->state,
            'locale' => $this->locale,
            'last_turn_at' => $this->datetime($this->last_turn_at),
            'closed_at' => $this->datetime($this->closed_at),
            'created_at' => $this->datetime($this->created_at),
            'messages' => AgentConversationMessageResource::collection($this->whenLoaded('messages')),
            'tool_invocations' => AgentToolInvocationResource::collection($this->whenLoaded('toolInvocations')),
            'handoffs' => AgentHandoffResource::collection($this->whenLoaded('handoffs')),
        ];
    }
}
