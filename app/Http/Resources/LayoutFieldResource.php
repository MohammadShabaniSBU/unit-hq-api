<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\LayoutFieldType;
use App\Support\Layout\NativeFields;
use Illuminate\Http\Request;

class LayoutFieldResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fieldType = $this->field_type instanceof LayoutFieldType
            ? $this->field_type
            : LayoutFieldType::from((string) $this->field_type);

        $native = null;
        if ($fieldType === LayoutFieldType::Native && $this->native_field_key) {
            $native = NativeFields::find($this->entity_type, $this->native_field_key)?->toArray();
        }

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'entity_type' => $this->entity_type?->value ?? $this->entity_type,
            'display_order' => $this->display_order,
            'field_type' => $fieldType->value,
            'native_field_key' => $this->native_field_key,
            'attribute_definition_id' => $this->attribute_definition_id,
            'native' => $native,
            'attribute_definition' => $this->when(
                $fieldType === LayoutFieldType::Attribute && $this->relationLoaded('attributeDefinition'),
                fn () => $this->attributeDefinition
                    ? AttributeDefinitionResource::make($this->attributeDefinition)->resolve()
                    : null,
            ),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
