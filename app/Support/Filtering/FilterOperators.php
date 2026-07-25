<?php

declare(strict_types=1);

namespace App\Support\Filtering;

final class FilterOperators
{
    /** @return list<string> */
    public static function forType(string $type): array
    {
        return match ($type) {
            'text', 'email' => ['eq', 'neq', 'contains', 'is_empty'],
            'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_empty'],
            'date' => ['eq', 'before', 'after', 'between', 'is_empty'],
            'boolean' => ['eq', 'is_empty'],
            'select' => ['eq', 'neq', 'in', 'is_empty'],
            'multiselect' => ['any_of', 'all_of', 'none_of', 'is_empty'],
            default => ['eq', 'neq', 'is_empty'],
        };
    }
}
