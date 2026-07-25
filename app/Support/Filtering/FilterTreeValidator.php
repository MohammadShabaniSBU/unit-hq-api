<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use App\Enums\AttributeEntityType;
use App\Models\AttributeDefinition;
use Illuminate\Validation\ValidationException;

/**
 * Recursive validation of a filter tree before it reaches FilterBuilder.
 */
final class FilterTreeValidator
{
    public const MAX_DEPTH = 4;

    public const MAX_CONDITIONS = 25;

    private int $conditionCount = 0;

    public function __construct(
        private readonly AttributeEntityType $entityType,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array{op: string, conditions: list<array<string, mixed>>}|null
     */
    public function validate(?array $filter): ?array
    {
        if ($filter === null || $filter === []) {
            return null;
        }

        $this->conditionCount = 0;
        $validated = $this->validateGroup($filter, depth: 1);

        if ($this->conditionCount > self::MAX_CONDITIONS) {
            throw ValidationException::withMessages([
                'filter' => ["Filter may contain at most ".self::MAX_CONDITIONS.' conditions.'],
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{op: string, conditions: list<array<string, mixed>>}
     */
    private function validateGroup(array $group, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'filter' => ['Filter nesting exceeds maximum depth of '.self::MAX_DEPTH.'.'],
            ]);
        }

        $op = strtolower((string) ($group['op'] ?? 'and'));

        if (! in_array($op, ['and', 'or'], true)) {
            throw ValidationException::withMessages([
                'filter' => ['Filter group op must be "and" or "or".'],
            ]);
        }

        if (! isset($group['conditions']) || ! is_array($group['conditions'])) {
            throw ValidationException::withMessages([
                'filter' => ['Filter group must include a conditions array.'],
            ]);
        }

        $conditions = [];

        foreach ($group['conditions'] as $condition) {
            if (! is_array($condition)) {
                throw ValidationException::withMessages([
                    'filter' => ['Each filter condition must be an object.'],
                ]);
            }

            if (isset($condition['conditions'])) {
                $conditions[] = $this->validateGroup($condition, $depth + 1);

                continue;
            }

            $conditions[] = $this->validateCondition($condition);
        }

        return [
            'op' => $op,
            'conditions' => $conditions,
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{field: string, op: string, value: mixed}
     */
    private function validateCondition(array $condition): array
    {
        $this->conditionCount++;

        if ($this->conditionCount > self::MAX_CONDITIONS) {
            throw ValidationException::withMessages([
                'filter' => ["Filter may contain at most ".self::MAX_CONDITIONS.' conditions.'],
            ]);
        }

        $fieldKey = $condition['field'] ?? null;
        $op = $condition['op'] ?? null;

        if (! is_string($fieldKey) || $fieldKey === '') {
            throw ValidationException::withMessages([
                'filter' => ['Each condition requires a field key.'],
            ]);
        }

        if (! is_string($op) || $op === '') {
            throw ValidationException::withMessages([
                'filter' => ["Condition on [{$fieldKey}] requires an operator."],
            ]);
        }

        $schema = $this->resolveField($fieldKey);

        if (! in_array($op, $schema['operators'], true)) {
            throw ValidationException::withMessages([
                'filter' => ["Operator [{$op}] is not valid for field [{$fieldKey}]."],
            ]);
        }

        $value = $condition['value'] ?? null;

        if ($op !== 'is_empty') {
            $value = $this->validateValue($fieldKey, $schema['type'], $op, $value);
        } else {
            $value = null;
        }

        return [
            'field' => $fieldKey,
            'op' => $op,
            'value' => $value,
        ];
    }

    /**
     * @return array{type: string, operators: list<string>, definition?: AttributeDefinition}
     */
    private function resolveField(string $fieldKey): array
    {
        $definitionId = AttributeFieldResolver::parseDefinitionId($fieldKey);

        if ($definitionId !== null) {
            $definition = AttributeFieldResolver::resolve($this->entityType, $definitionId);

            if ($definition === null) {
                throw ValidationException::withMessages([
                    'filter' => ["Unknown or archived attribute field [{$fieldKey}]."],
                ]);
            }

            $type = $definition->type->value;

            return [
                'type' => $type,
                'operators' => FilterOperators::forType($type),
                'definition' => $definition,
            ];
        }

        $native = FilterableFields::find($this->entityType, $fieldKey);

        if ($native === null) {
            throw ValidationException::withMessages([
                'filter' => ["Unknown filter field [{$fieldKey}]."],
            ]);
        }

        return [
            'type' => $native->type === 'email' ? 'text' : $native->type,
            'operators' => $native->operators,
        ];
    }

    private function validateValue(string $fieldKey, string $type, string $op, mixed $value): mixed
    {
        if ($op === 'between') {
            if (! is_array($value) || count($value) !== 2) {
                throw ValidationException::withMessages([
                    'filter' => ["Field [{$fieldKey}] between operator requires a two-value array."],
                ]);
            }

            return [
                $this->castScalar($fieldKey, $type, $value[0]),
                $this->castScalar($fieldKey, $type, $value[1]),
            ];
        }

        if (in_array($op, ['in', 'any_of', 'all_of', 'none_of'], true)) {
            if (! is_array($value) || $value === []) {
                throw ValidationException::withMessages([
                    'filter' => ["Field [{$fieldKey}] operator [{$op}] requires a non-empty array value."],
                ]);
            }

            return array_map(
                fn (mixed $item) => $this->castScalar($fieldKey, $type === 'multiselect' ? 'number' : $type, $item),
                array_values($value),
            );
        }

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'filter' => ["Field [{$fieldKey}] requires a value for operator [{$op}]."],
            ]);
        }

        return $this->castScalar($fieldKey, $type, $value);
    }

    private function castScalar(string $fieldKey, string $type, mixed $value): string|int|float|bool
    {
        return match ($type) {
            'number', 'multiselect' => is_numeric($value)
                ? (str_contains((string) $value, '.') ? (float) $value : (int) $value)
                : throw ValidationException::withMessages([
                    'filter' => ["Field [{$fieldKey}] expects a numeric value."],
                ]),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw ValidationException::withMessages([
                    'filter' => ["Field [{$fieldKey}] expects a boolean value."],
                ]),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)
                ? substr($value, 0, 10)
                : throw ValidationException::withMessages([
                    'filter' => ["Field [{$fieldKey}] expects a date (Y-m-d)."],
                ]),
            default => is_scalar($value)
                ? (string) $value
                : throw ValidationException::withMessages([
                    'filter' => ["Field [{$fieldKey}] expects a scalar value."],
                ]),
        };
    }
}
