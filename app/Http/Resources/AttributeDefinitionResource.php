<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AttributeDefinitionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'entity_type'      => $this->entity_type?->value ?? $this->entity_type,
            'key'              => $this->key,
            'label'            => $this->label,
            'type'             => $this->type?->value ?? $this->type,
            'group_name'       => $this->group_name,
            'display_order'    => $this->display_order,
            'is_required'      => $this->is_required,
            'is_promoted'      => $this->is_promoted,
            'usage_count'      => $this->usage_count,
            'promoted_column'  => $this->promoted_column,
            'archived_at'      => $this->datetime($this->archived_at),
            'options'          => AttributeOptionResource::collection($this->whenLoaded('options')),
            'created_at'       => $this->datetime($this->created_at),
            'updated_at'       => $this->datetime($this->updated_at),
        ];
    }
}
