<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EmailBlockResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'type'  => $this->type,
            'props' => $this->props ?? [],
        ];
    }
}
