<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Support\Filtering\AttributeFieldResolver;
use Throwable;

/**
 * Loads EAV values keyed as attr:{id} for snapshot or live evaluation.
 */
final class CustomAttributeBag
{
    /**
     * @return array<string, mixed>  attr:{id} => scalar|list
     */
    public static function forEntity(string $entityType, int|string $entityId): array
    {
        try {
            $type = AttributeEntityType::from($entityType);
        } catch (Throwable) {
            return [];
        }

        $definitionIds = AttributeDefinition::query()
            ->where('entity_type', $type)
            ->pluck('id');

        if ($definitionIds->isEmpty()) {
            return [];
        }

        $values = AttributeValue::query()
            ->with(['definition', 'options'])
            ->where('entity_id', $entityId)
            ->whereIn('definition_id', $definitionIds)
            ->get();

        $bag = [];
        foreach ($values as $value) {
            $definition = $value->definition;
            if ($definition === null) {
                continue;
            }

            $key = AttributeFieldResolver::fieldKey((int) $definition->id);
            $bag[$key] = self::resolvedValue($definition->type, $value);
        }

        return $bag;
    }

    private static function resolvedValue(AttributeType $type, AttributeValue $value): mixed
    {
        return match ($type) {
            AttributeType::Text => $value->value_text,
            AttributeType::Number => $value->value_number,
            AttributeType::Date => $value->value_date?->format('Y-m-d'),
            AttributeType::Boolean => $value->value_boolean,
            AttributeType::Select => $value->value_option_id,
            AttributeType::Multiselect => $value->relationLoaded('options')
                ? $value->options->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : [],
        };
    }
}
