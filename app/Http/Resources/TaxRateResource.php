<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TaxRateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'code'           => $this->code,
            'rate'           => $this->rate,
            'jurisdiction'   => $this->jurisdiction,
            'is_default'     => $this->is_default,
            'effective_from' => $this->date($this->effective_from),
            'effective_to'   => $this->date($this->effective_to),
            'created_at'     => $this->datetime($this->created_at),
        ];
    }
}
