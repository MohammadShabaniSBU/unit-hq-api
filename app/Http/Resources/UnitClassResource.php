<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UnitClassResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'code'             => $this->code,
            'label'            => $this->label,
            'size'             => $this->size,
            'current_price_id' => $this->current_price_id,
            'tax_rate_code'    => $this->tax_rate_code,
            'created_at'       => $this->datetime($this->created_at),
            'updated_at'       => $this->datetime($this->updated_at),
            'current_price'    => PriceResource::make($this->whenLoaded('currentPrice')),
            'unit_class_rates' => UnitClassRateResource::collection($this->whenLoaded('unitClassRates')),
        ];
    }
}
