<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Discounts\DiscountAlignment;
use Illuminate\Http\Request;

class DiscountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $kind = $this->kind instanceof \BackedEnum ? $this->kind : $this->kind;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $kind instanceof \BackedEnum ? $kind->value : $kind,
            'params' => $this->params ?? [],
            'applies_to' => $this->applies_to,
            'tracks_rate_changes' => (bool) $this->tracks_rate_changes,
            'agent_offerable' => (bool) $this->agent_offerable,
            'customer_terms' => $this->customer_terms ?? null,
            'usage_count' => $this->usageCount(),
            'alignment_warnings' => DiscountAlignment::warnings(
                $this->kind,
                $this->params ?? [],
            ),
            'archived_at' => $this->datetime($this->archived_at),
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
