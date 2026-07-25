<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use Illuminate\Support\Facades\Cache;

final class FilterSchemaResponder
{
    private const CACHE_TTL_SECONDS = 300;

    public static function cacheKey(AttributeEntityType|string $entityType): string
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType->value
            : $entityType;

        return "filter_schema:{$type}";
    }

    public static function forget(AttributeEntityType|string $entityType): void
    {
        Cache::forget(self::cacheKey($entityType));
    }

    /**
     * @return list<array{key: string, label: string, type: string, operators: list<string>, custom?: true, options?: list<array{value: string|int|bool, label: string}>}>
     */
    public static function for(AttributeEntityType|string $entityType): array
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return Cache::remember(
            self::cacheKey($type),
            self::CACHE_TTL_SECONDS,
            fn () => self::build($type),
        );
    }

    /**
     * @return list<array{key: string, label: string, type: string, operators: list<string>, custom?: true, options?: list<array{value: string|int|bool, label: string}>}>
     */
    private static function build(AttributeEntityType $entityType): array
    {
        $fields = array_map(
            fn (FilterableField $field) => $field->toSchemaArray(),
            FilterableFields::for($entityType),
        );

        foreach (AttributeFieldResolver::activeDefinitions($entityType) as $definition) {
            $fields[] = self::attributeField($definition)->toSchemaArray();
        }

        return $fields;
    }

    public static function attributeField(AttributeDefinition $definition): FilterableField
    {
        $type = $definition->type->value;
        $options = null;

        if ($definition->type->requiresOptions()) {
            $options = $definition->options
                ->map(fn ($option) => [
                    'value' => $option->id,
                    'label' => $option->label,
                ])
                ->values()
                ->all();
        }

        if ($definition->type === AttributeType::Boolean) {
            $options = [
                ['value' => true, 'label' => 'Yes'],
                ['value' => false, 'label' => 'No'],
            ];
        }

        return new FilterableField(
            key: AttributeFieldResolver::fieldKey($definition->id),
            label: $definition->label,
            type: $type,
            operators: FilterOperators::forType($type),
            custom: true,
            options: $options,
        );
    }
}
