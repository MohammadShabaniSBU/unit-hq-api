<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DealResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'contact_id'             => $this->contact_id,
            'status'                 => $this->status,
            'expected_move_in'       => $this->date($this->expected_move_in),
            'expected_stay_length'   => $this->expected_stay_length,
            'expected_stay_period'   => $this->expected_stay_period,
            'storage_reason'         => $this->storage_reason,
            'desired_size'           => $this->desired_size,
            'desired_unit_class_id'  => $this->desired_unit_class_id,
            'intent_notes'           => $this->intent_notes,
            'created_at'             => $this->datetime($this->created_at),
            'updated_at'             => $this->datetime($this->updated_at),
            'desired_unit_class'     => UnitClassResource::make($this->whenLoaded('desiredUnitClass')),
            'contact'                => $this->whenLoaded('contact', fn () => [
                'id'   => $this->contact->id,
                'name' => trim($this->contact->first_name . ' ' . $this->contact->last_name),
            ]),
        ];
    }
}
