<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InteractionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'deal_id' => $this->deal_id,
            'channel' => $this->channel,
            'direction' => $this->direction,
            'occurred_at' => $this->datetime($this->occurred_at),
            'content' => $this->content,
            'summary' => $this->summary,
            'metadata' => $this->metadata,
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
