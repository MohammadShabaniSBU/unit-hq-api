<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UnitClassRateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'unit_class_id' => $this->unit_class_id,
            'site_id'       => $this->site_id,
            'price_id'      => $this->price_id,
            'created_at'    => $this->datetime($this->created_at),
            'unit_class'    => UnitClassResource::make($this->whenLoaded('unitClass')),
            'site'          => SiteResource::make($this->whenLoaded('site')),
            'price'         => PriceResource::make($this->whenLoaded('price')),
        ];
    }
}
