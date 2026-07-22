<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ReservationCardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'unit_id' => $this->unit_id,
            'deal_id' => $this->deal_id,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'expires_at' => $this->datetime($this->expires_at),
            'updated_at' => $this->datetime($this->updated_at),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => trim("{$this->contact->first_name} {$this->contact->last_name}"),
            ]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit === null ? null : [
                'id' => $this->unit->id,
                'unit_number' => $this->unit->unit_number,
                'site' => $this->unit->relationLoaded('site') && $this->unit->site !== null
                    ? [
                        'id' => $this->unit->site->id,
                        'name' => $this->unit->site->name,
                    ]
                    : null,
            ]),
        ];
    }
}
