<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ContactCardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'email' => $this->email,
            'status' => $this->status instanceof \BackedEnum
                ? $this->status->value
                : $this->status,
            'deals_count' => $this->whenCounted('deals'),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
