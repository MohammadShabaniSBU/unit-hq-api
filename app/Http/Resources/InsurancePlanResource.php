<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InsurancePlanResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'coverage'    => $this->coverage,
            'currency'    => $this->currency,
            'created_at'  => $this->datetime($this->created_at),
            'updated_at'  => $this->datetime($this->updated_at),
        ];
    }
}
