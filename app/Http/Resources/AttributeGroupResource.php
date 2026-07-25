<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AttributeGroupResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type?->value ?? $this->entity_type,
            'key' => $this->key,
            'label' => $this->label,
            'display_order' => $this->display_order,
            'is_system' => $this->is_system,
            'fields' => LayoutFieldResource::collection($this->whenLoaded('fields')),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
