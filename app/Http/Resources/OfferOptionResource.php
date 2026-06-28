<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class OfferOptionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'offer_id'           => $this->offer_id,
            'unit_class_rate_id' => $this->unit_class_rate_id,
            'unit_id'            => $this->unit_id,
            'discount_id'        => $this->discount_id,
            'label'              => $this->label,
            'description'        => $this->description,
            'display_order'      => $this->display_order,
            'selected_at'        => $this->datetime($this->selected_at),
            'created_at'         => $this->datetime($this->created_at),
            'updated_at'         => $this->datetime($this->updated_at),
            'unit_class_rate'    => UnitClassRateResource::make($this->whenLoaded('unitClassRate')),
            'unit'               => UnitResource::make($this->whenLoaded('unit')),
            'discount'           => $this->whenLoaded('discount', fn () => [
                'id'             => $this->discount->id,
                'code'           => $this->discount->code,
                'label'          => $this->discount->label,
                'discount_type'  => $this->discount->discount_type,
                'value'          => $this->discount->value,
                'effective_from' => $this->date($this->discount->effective_from),
                'effective_to'   => $this->date($this->discount->effective_to),
            ]),
        ];
    }
}
