<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SiteResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'address'       => $this->address,
            'location'      => $this->location,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'city'          => $this->city,
            'country'       => $this->country,
            'created_at'    => $this->datetime($this->created_at),
            'updated_at'    => $this->datetime($this->updated_at),
            'unit_class_rates' => UnitClassRateResource::collection($this->whenLoaded('unitClassRates')),
        ];
    }
}
