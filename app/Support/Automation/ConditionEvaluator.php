<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * Pure in-memory condition evaluation for trigger pre-filters and logic.branch.
 * Supports automation filter operators (equals/contains/changed/…) used by the panel.
 */
final class ConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $group  { logic: 'and'|'or', conditions: [...] }
     * @param  array<string, mixed>  $values  field => current value
     * @param  array<string, mixed>  $oldValues  field => previous value (for "changed")
     */
    public static function matchesGroup(array $group, array $values, array $oldValues = []): bool
    {
        $logic = strtolower((string) ($group['logic'] ?? $group['op'] ?? 'and'));
        $conditions = $group['conditions'] ?? [];

        if ($conditions === []) {
            return true;
        }

        $results = [];
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['logic']) || (isset($condition['op']) && isset($condition['conditions']))) {
                $results[] = self::matchesGroup($condition, $values, $oldValues);
                continue;
            }

            $results[] = self::matchesCondition($condition, $values, $oldValues);
        }

        if ($results === []) {
            return true;
        }

        return $logic === 'or'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $condition  { field?, operator|op, value? }
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $oldValues
     */
    public static function matchesCondition(array $condition, array $values, array $oldValues = []): bool
    {
        $field = (string) ($condition['field'] ?? $condition['property'] ?? '');
        $operator = (string) ($condition['operator'] ?? $condition['op'] ?? 'equals');
        $expected = $condition['value'] ?? null;
        $actual = $field !== '' ? ($values[$field] ?? null) : ($values['__value'] ?? null);
        $old = $field !== '' ? ($oldValues[$field] ?? null) : ($oldValues['__value'] ?? null);

        return match ($operator) {
            'equals', 'eq' => self::looseEquals($actual, $expected),
            'not_equals', 'neq' => ! self::looseEquals($actual, $expected),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'not_contains' => is_string($actual) && is_string($expected) && ! str_contains($actual, $expected),
            'starts_with' => is_string($actual) && is_string($expected) && str_starts_with($actual, $expected),
            'ends_with' => is_string($actual) && is_string($expected) && str_ends_with($actual, $expected),
            'is_empty' => self::isEmpty($actual),
            'is_not_empty' => ! self::isEmpty($actual),
            'greater_than', 'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than', 'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'changed' => ! self::looseEquals($actual, $old),
            default => false,
        };
    }

    /**
     * Property-update style: list of { operator, value } against a single property's new/old.
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    public static function matchesPropertyConditions(array $conditions, mixed $newValue, mixed $oldValue): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $bag = ['__value' => $newValue];
            $old = ['__value' => $oldValue];

            if (! self::matchesCondition($condition, $bag, $old)) {
                return false;
            }
        }

        return true;
    }

    private static function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if (is_bool($a) || is_bool($b)) {
            return filter_var($a, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                === filter_var($b, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return (string) $a === (string) $b;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
