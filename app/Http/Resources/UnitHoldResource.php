<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UnitHoldResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'unit_id'        => $this->unit_id,
            'hold_type'      => $this->hold_type instanceof \BackedEnum
                ? $this->hold_type->value
                : $this->hold_type,
            'reservation_id' => $this->reservation_id,
            'starts_on'      => $this->date($this->starts_on),
            'ends_on'        => $this->date($this->ends_on),
            'released_at'    => $this->datetime($this->released_at),
            'reason'         => $this->reason,
            'created_by'     => $this->created_by,
            'created_at'     => $this->datetime($this->created_at),
            'updated_at'     => $this->datetime($this->updated_at),
        ];
    }
}
