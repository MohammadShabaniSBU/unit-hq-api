<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SiteResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'address_line_2' => $this->address_line_2,
            'location' => $this->location,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'state_region' => $this->state_region,
            'country' => $this->country,
            'country_id' => $this->country_id,
            'timezone' => $this->timezone,
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
            'unit_class_rates' => UnitClassRateResource::collection($this->whenLoaded('unitClassRates')),
        ];
    }
}
