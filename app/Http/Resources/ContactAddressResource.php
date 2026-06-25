<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ContactAddressResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'contact_id'  => $this->contact_id,
            'type'        => $this->type,
            'line1'       => $this->line1,
            'line2'       => $this->line2,
            'city'        => $this->city,
            'state'       => $this->state,
            'postal_code' => $this->postal_code,
            'country_id'  => $this->country_id,
            'country'     => $this->whenLoaded('country'),
            'label'       => $this->label,
            'is_primary'  => $this->is_primary,
        ];
    }
}
