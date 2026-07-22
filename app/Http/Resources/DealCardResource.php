<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DealCardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status instanceof \BackedEnum
                ? $this->status->value
                : $this->status,
            'expected_move_in' => $this->date($this->expected_move_in),
            'updated_at' => $this->datetime($this->updated_at),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => trim("{$this->contact->first_name} {$this->contact->last_name}"),
                'email' => $this->contact->email,
            ]),
            'desired_unit_class' => $this->whenLoaded('desiredUnitClass', fn () => $this->desiredUnitClass === null ? null : [
                'id' => $this->desiredUnitClass->id,
                'label' => $this->desiredUnitClass->label,
            ]),
        ];
    }
}
