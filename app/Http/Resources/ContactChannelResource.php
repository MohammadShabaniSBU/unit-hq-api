<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ContactChannelResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'contact_id' => $this->contact_id,
            'type'       => $this->type,
            'value'      => $this->value,
            'label'      => $this->label,
            'is_primary' => $this->is_primary,
            'opted_in'   => $this->opted_in,
        ];
    }
}
