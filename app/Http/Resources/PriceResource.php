<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PriceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'amount'           => $this->amount,
            'currency'         => $this->currency,
            'billing_period'   => $this->billing_period,
            'effective_from'   => $this->date($this->effective_from),
            'effective_to'     => $this->date($this->effective_to),
            'created_by'       => $this->created_by,
            'created_at'       => $this->datetime($this->created_at),
        ];
    }
}
