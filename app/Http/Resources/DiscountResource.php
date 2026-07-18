<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DiscountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'code'            => $this->code,
            'label'           => $this->label,
            'discount_type'   => $this->discount_type,
            'value'           => $this->value,
            'duration_months' => $this->duration_months,
            'effective_from'  => $this->date($this->effective_from),
            'effective_to'    => $this->date($this->effective_to),
            'created_at'      => $this->datetime($this->created_at),
        ];
    }
}
