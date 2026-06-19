<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class LeaseResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'unit_id'          => $this->unit_id,
            'contact_id'       => $this->contact_id,
            'reservation_id'   => $this->reservation_id,
            'deal_id'          => $this->deal_id,
            'start_date'       => $this->date($this->start_date),
            'end_date'         => $this->date($this->end_date),
            'actual_rate'      => $this->actual_rate,
            'actual_insurance' => $this->actual_insurance,
            'status'           => $this->status,
            'signed_at'        => $this->datetime($this->signed_at),
            'created_at'       => $this->datetime($this->created_at),
            'updated_at'       => $this->datetime($this->updated_at),
            'unit'             => $this->whenLoaded('unit', fn () => [
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
            'contact'          => $this->whenLoaded('contact', fn () => [
                'id'   => $this->contact->id,
                'name' => trim($this->contact->first_name . ' ' . $this->contact->last_name),
            ]),
            'reservation'      => $this->whenLoaded('reservation', fn () =>
                $this->reservation ? [
                    'id'     => $this->reservation->id,
                    'status' => $this->reservation->status,
                ] : null
            ),
        ];
    }
}
