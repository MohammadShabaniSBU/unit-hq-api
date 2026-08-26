<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AgentChannelBindingResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $agent = $this->agent;
        $site = $this->site;
        $updatedBy = $this->updatedBy;

        return [
            'id' => $this->id,
            'ai_agent_id' => $this->ai_agent_id,
            'agent' => [
                'id' => $agent->id,
                'key' => $agent->key,
                'name' => $agent->name,
            ],
            'channel' => $this->channel->value,
            'site_id' => $this->site_id,
            'site' => $site === null ? null : [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'mode' => $this->mode->value,
            'audience' => $this->audience->value,
            'outside_hours' => $this->outside_hours->value,
            'archived_at' => $this->datetime($this->archived_at),
            'updated_by' => $updatedBy === null ? null : [
                'id' => $updatedBy->id,
                'name' => $updatedBy->name,
            ],
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
