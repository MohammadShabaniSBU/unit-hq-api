<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AttributeType;
use Illuminate\Http\Request;

class AttributeValueResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $definition = $this->relationLoaded('definition') ? $this->definition : null;
        $type = $definition?->type;

        return [
            'id' => $this->id,
            'definition_id' => $this->definition_id,
            'entity_id' => $this->entity_id,
            'value' => $this->resolvedValue($type),
            'definition' => $this->when(
                $definition !== null,
                fn () => AttributeDefinitionResource::make($definition)->resolve(),
            ),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }

    private function resolvedValue(?AttributeType $type): mixed
    {
        return match ($type) {
            AttributeType::Text => $this->value_text,
            AttributeType::Number => $this->value_number,
            AttributeType::Date => $this->value_date?->format('Y-m-d'),
            AttributeType::Boolean => $this->value_boolean,
            AttributeType::Select => $this->value_option_id,
            AttributeType::Multiselect => $this->relationLoaded('options')
                ? $this->options->pluck('id')->values()->all()
                : [],
            default => null,
        };
    }
}
