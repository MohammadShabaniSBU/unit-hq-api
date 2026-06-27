<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EmailTemplateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'blocks'     => EmailBlockResource::collection($this->emailBlocks),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
