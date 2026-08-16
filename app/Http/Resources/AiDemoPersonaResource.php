<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AiDemoPersonaResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $site = $this->relationLoaded('sites') ? $this->sites->first() : null;

        return [
            'id' => $this->id,
            'name' => trim($this->first_name.' '.$this->last_name),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'site_id' => $site?->id,
            'site' => $site === null ? null : [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'has_contract' => (bool) ($this->has_contract ?? false),
            'has_balance' => (bool) ($this->has_balance ?? false),
            'has_delinquency' => (bool) ($this->has_delinquency ?? false),
        ];
    }
}
