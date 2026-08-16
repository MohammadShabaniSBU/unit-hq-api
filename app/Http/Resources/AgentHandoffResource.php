<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AgentHandoffResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason instanceof \BackedEnum ? $this->reason->value : $this->reason,
            'trigger_source' => $this->trigger_source instanceof \BackedEnum
                ? $this->trigger_source->value
                : $this->trigger_source,
            'detail' => $this->detail,
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
