<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ReservationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'unit_id'         => $this->unit_id,
            'contact_id'      => $this->contact_id,
            'offer_option_id' => $this->offer_option_id,
            'status'          => $this->status,
            'expires_at'      => $this->datetime($this->expires_at),
            'hold_notes'      => $this->hold_notes,
            'created_at'      => $this->datetime($this->created_at),
            'updated_at'      => $this->datetime($this->updated_at),
            'unit'            => $this->whenLoaded('unit', fn () => [
                'id'          => $this->unit->id,
                'unit_number' => $this->unit->unit_number,
                'site'        => $this->unit->relationLoaded('site') ? [
                    'id'   => $this->unit->site->id,
                    'name' => $this->unit->site->name,
                ] : null,
                'unit_class'  => $this->unit->relationLoaded('unitClass') ? [
                    'id'    => $this->unit->unitClass->id,
                    'label' => $this->unit->unitClass->label,
                    'code'  => $this->unit->unitClass->code_slug,
                ] : null,
            ]),
            'contact'         => $this->whenLoaded('contact', fn () => [
                'id'   => $this->contact->id,
                'name' => trim($this->contact->first_name . ' ' . $this->contact->last_name),
            ]),
            'lease'           => LeaseResource::make($this->whenLoaded('lease')),
        ];
    }
}
