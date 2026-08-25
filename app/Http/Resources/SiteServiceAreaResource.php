<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SiteServiceAreaResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'kind' => $this->kind?->value ?? $this->kind,
            'value' => $this->value,
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
