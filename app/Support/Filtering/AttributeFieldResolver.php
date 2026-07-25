<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use App\Enums\AttributeEntityType;
use App\Models\AttributeDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves attr:{id} keys against active attribute definitions for an entity type.
 */
final class AttributeFieldResolver
{
    private const CACHE_TTL_SECONDS = 300;

    public static function cacheKey(AttributeEntityType|string $entityType): string
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType->value
            : $entityType;

        return "filter_attr_defs:{$type}";
    }

    public static function forget(AttributeEntityType|string $entityType): void
    {
        Cache::forget(self::cacheKey($entityType));
        FilterSchemaResponder::forget($entityType);
    }

    /**
     * @return Collection<int, AttributeDefinition>
     */
    public static function activeDefinitions(AttributeEntityType|string $entityType): Collection
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return Cache::remember(
            self::cacheKey($type),
            self::CACHE_TTL_SECONDS,
            fn () => AttributeDefinition::query()
                ->active()
                ->with('options')
                ->where('entity_type', $type)
                ->orderBy('display_order')
                ->orderBy('label')
                ->get(),
        );
    }

    public static function resolve(AttributeEntityType|string $entityType, int $definitionId): ?AttributeDefinition
    {
        return self::activeDefinitions($entityType)->firstWhere('id', $definitionId);
    }

    public static function fieldKey(int $definitionId): string
    {
        return "attr:{$definitionId}";
    }

    public static function parseDefinitionId(string $fieldKey): ?int
    {
        if (! preg_match('/^attr:(\d+)$/', $fieldKey, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
