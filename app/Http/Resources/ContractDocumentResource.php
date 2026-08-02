<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ContractDocumentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->whenLoaded('templateVariant');

        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'template_family_id' => $this->template_family_id,
            'template_variant_id' => $this->template_variant_id,
            'locale' => $variant ? $variant->locale : null,
            'rendered_at' => $this->datetime($this->rendered_at),
            'sha256' => $this->sha256,
            'sha256_prefix' => substr((string) $this->sha256, 0, 8),
            'status' => $this->status?->value ?? $this->status,
            'envelope_id' => $this->envelope_id,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
