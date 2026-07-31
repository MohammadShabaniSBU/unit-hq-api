<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PriceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'priceable_type'   => $this->priceable_type,
            'priceable_id'     => $this->priceable_id,
            'scope'            => $this->scope,
            'amount'           => $this->amount,
            'currency'         => $this->currency,
            'effective_from'   => $this->date($this->effective_from),
            'effective_to'     => $this->date($this->effective_to),
            'created_by'       => $this->created_by,
            'created_at'       => $this->datetime($this->created_at),
        ];
    }
}
