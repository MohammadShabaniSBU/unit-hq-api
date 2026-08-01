<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\ConditionSource;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;

/**
 * Typed in-memory condition evaluation for triggers, logic.branch, and run guards.
 *
 * @see docs/automation-conditions.md
 */
final class ConditionEvaluator
{
    /**
     * Full evaluation with warnings and explicit context.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $values
     */
    public static function evaluate(array $group, array $values, ConditionContext $context): ConditionResult
    {
        return self::evaluateGroup($group, $values, $context);
    }

    /**
     * Bool convenience wrapper (ignores warnings).
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $oldValues
     */
    public static function matchesGroup(array $group, array $values, array $oldValues = []): bool
    {
        $context = new ConditionContext(
            source: ConditionSource::Snapshot,
            oldValues: $oldValues,
        );

        return self::evaluate($group, $values, $context)->passed;
    }

    /**
     * Evaluate with an explicit context; returns bool only.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $values
     */
    public static function matchesGroupWithContext(array $group, array $values, ConditionContext $context): bool
    {
        return self::evaluate($group, $values, $context)->passed;
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

        $context = new ConditionContext(
            source: ConditionSource::Snapshot,
            oldValues: ['__value' => $oldValue],
            fieldTypes: ['__value' => 'text'],
        );

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $result = self::evaluateCondition($condition, ['__value' => $newValue], $context);
            if (! $result->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $values
     */
    private static function evaluateGroup(array $group, array $values, ConditionContext $context): ConditionResult
    {
        $logic = strtolower((string) ($group['logic'] ?? $group['op'] ?? 'and'));
        $conditions = $group['conditions'] ?? [];

        if (! is_array($conditions) || $conditions === []) {
            return ConditionResult::pass();
        }

        if ($logic === 'not') {
            return self::evaluateNot($conditions, $values, $context);
        }

        $warnings = [];
        $isOr = $logic === 'or';

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $child = self::isGroup($condition)
                ? self::evaluateGroup($condition, $values, $context)
                : self::evaluateCondition($condition, $values, $context);

            $warnings = array_merge($warnings, $child->warnings);

            if ($isOr) {
                if ($child->passed) {
                    return ConditionResult::pass($warnings);
                }
            } elseif (! $child->passed) {
                return ConditionResult::fail($warnings);
            }
        }

        return $isOr
            ? ConditionResult::fail($warnings)
            : ConditionResult::pass($warnings);
    }

    /**
     * @param  list<mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    private static function evaluateNot(array $conditions, array $values, ConditionContext $context): ConditionResult
    {
        $warnings = [];
        $first = null;

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $first = $condition;
            break;
        }

        if ($first === null) {
            return ConditionResult::pass();
        }

        if (count(array_filter($conditions, 'is_array')) > 1) {
            $warnings[] = 'NOT group has multiple children; only the first is evaluated';
        }

        $child = self::isGroup($first)
            ? self::evaluateGroup($first, $values, $context)
            : self::evaluateCondition($first, $values, $context);

        $warnings = array_merge($warnings, $child->warnings);

        return new ConditionResult(! $child->passed, $warnings);
    }

    /** @param  array<string, mixed>  $node */
    private static function isGroup(array $node): bool
    {
        return isset($node['logic'])
            || (isset($node['op']) && isset($node['conditions']) && is_array($node['conditions']));
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $values
     */
    private static function evaluateCondition(array $condition, array $values, ConditionContext $context): ConditionResult
    {
        $field = (string) ($condition['field'] ?? $condition['property'] ?? '');
        $operator = strtolower((string) ($condition['operator'] ?? $condition['op'] ?? 'equals'));
        $expected = $condition['value'] ?? null;

        $missing = $field !== '' && ! array_key_exists($field, $values);
        $actual = $field !== ''
            ? ($missing ? null : $values[$field])
            : ($values['__value'] ?? null);

        $resolveField = $field !== '' ? $field : '__value';
        $resolved = FieldTypeResolver::resolve($resolveField, $context);
        $type = $resolved['type'];
        $warnings = [];

        if ($resolved['warning'] !== null) {
            $warnings[] = $resolved['warning'];

            // Deleted/unknown attr: warn-and-false for any op except we still allow is_empty on missing.
            if (str_starts_with($resolveField, 'attr:')) {
                if (in_array($operator, ['is_empty', 'is_not_empty'], true)) {
                    $empty = self::isEmpty($actual);

                    return new ConditionResult(
                        $operator === 'is_empty' ? $empty : ! $empty,
                        $warnings,
                    );
                }

                return ConditionResult::fail($warnings);
            }
        }

        return match ($operator) {
            'equals', 'eq' => self::opEquals($actual, $expected, $type, $context, $warnings),
            'not_equals', 'neq' => self::opNotEquals($actual, $expected, $type, $context, $warnings),
            'contains' => self::opContains($actual, $expected, $type, $warnings, false),
            'not_contains' => self::opContains($actual, $expected, $type, $warnings, true),
            'starts_with' => self::opStringPrefix($actual, $expected, $type, $warnings, true),
            'ends_with' => self::opStringPrefix($actual, $expected, $type, $warnings, false),
            'is_empty' => new ConditionResult(self::isEmpty($actual), $warnings),
            'is_not_empty' => new ConditionResult(! self::isEmpty($actual), $warnings),
            'greater_than', 'gt' => self::opCompare($actual, $expected, $type, $context, $warnings, 1, false),
            'less_than', 'lt' => self::opCompare($actual, $expected, $type, $context, $warnings, -1, false),
            'gte' => self::opCompare($actual, $expected, $type, $context, $warnings, 1, true),
            'lte' => self::opCompare($actual, $expected, $type, $context, $warnings, -1, true),
            'in' => self::opIn($actual, $expected, $type, $context, $warnings, false),
            'not_in' => self::opIn($actual, $expected, $type, $context, $warnings, true),
            'changed' => self::opChanged($field, $actual, $type, $context, $warnings),
            default => ConditionResult::fail(array_merge($warnings, ["Unknown operator [{$operator}]"])),
        };
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function opEquals(
        mixed $actual,
        mixed $expected,
        string $type,
        ConditionContext $context,
        array $warnings,
    ): ConditionResult {
        if ($actual === null || $expected === null) {
            return ConditionResult::fail($warnings);
        }

        $cmp = self::typedCompare($actual, $expected, $type, $context);
        if ($cmp['error'] !== null) {
            return ConditionResult::fail(array_merge($warnings, [$cmp['error']]));
        }

        return new ConditionResult($cmp['order'] === 0, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function opNotEquals(
        mixed $actual,
        mixed $expected,
        string $type,
        ConditionContext $context,
        array $warnings,
    ): ConditionResult {
        if ($actual === null || $expected === null) {
            return ConditionResult::fail($warnings);
        }

        $cmp = self::typedCompare($actual, $expected, $type, $context);
        if ($cmp['error'] !== null) {
            return ConditionResult::fail(array_merge($warnings, [$cmp['error']]));
        }

        return new ConditionResult($cmp['order'] !== 0, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function opContains(
        mixed $actual,
        mixed $expected,
        string $type,
        array $warnings,
        bool $negate,
    ): ConditionResult {
        if ($actual === null || $expected === null) {
            return ConditionResult::fail($warnings);
        }

        if ($type !== 'text' && $type !== 'email') {
            return ConditionResult::fail(array_merge($warnings, ["Operator contains requires text field, got [{$type}]"]));
        }

        $a = self::castString($actual);
        $b = self::castString($expected);
        if ($a === null || $b === null) {
            return ConditionResult::fail(array_merge($warnings, ['contains: values are not strings']));
        }

        $hit = str_contains($a, $b);

        return new ConditionResult($negate ? ! $hit : $hit, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function opStringPrefix(
        mixed $actual,
        mixed $expected,
        string $type,
        array $warnings,
        bool $starts,
    ): ConditionResult {
        if ($actual === null || $expected === null) {
            return ConditionResult::fail($warnings);
        }

        if ($type !== 'text' && $type !== 'email') {
            return ConditionResult::fail(array_merge($warnings, ['Prefix/suffix operators require text field']));
        }

        $a = self::castString($actual);
        $b = self::castString($expected);
        if ($a === null || $b === null) {
            return ConditionResult::fail(array_merge($warnings, ['Prefix/suffix: values are not strings']));
        }

        $hit = $starts ? str_starts_with($a, $b) : str_ends_with($a, $b);

        return new ConditionResult($hit, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     * @param  int  $want  1 for gt/gte, -1 for lt/lte
     */
    private static function opCompare(
        mixed $actual,
        mixed $expected,
        string $type,
        ConditionContext $context,
        array $warnings,
        int $want,
        bool $orEqual,
    ): ConditionResult {
        if ($actual === null || $expected === null) {
            return ConditionResult::fail($warnings);
        }

        $cmp = self::typedCompare($actual, $expected, $type, $context);
        if ($cmp['error'] !== null) {
            return ConditionResult::fail(array_merge($warnings, [$cmp['error']]));
        }

        $order = $cmp['order'];
        $passed = $orEqual
            ? ($order === $want || $order === 0)
            : ($order === $want);

        return new ConditionResult($passed, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function opIn(
        mixed $actual,
        mixed $expected,
        string $type,
        ConditionContext $context,
        array $warnings,
        bool $negate,
    ): ConditionResult {
        if (! is_array($expected)) {
            return ConditionResult::fail(array_merge($warnings, ['in/not_in expected value must be a list']));
        }

        if ($expected === []) {
            // empty option list: in → false, not_in → true
            return new ConditionResult($negate, $warnings);
        }

        if ($actual === null) {
            return ConditionResult::fail($warnings);
        }

        if ($type === 'multiselect') {
            if (! is_array($actual)) {
                return ConditionResult::fail(array_merge($warnings, ['multiselect in/not_in requires list actual']));
            }

            $overlap = false;
            foreach ($actual as $item) {
                foreach ($expected as $candidate) {
                    $cmp = self::typedCompare($item, $candidate, 'select', $context);
                    if ($cmp['error'] === null && $cmp['order'] === 0) {
                        $overlap = true;
                        break 2;
                    }
                }
            }

            return new ConditionResult($negate ? ! $overlap : $overlap, $warnings);
        }

        $member = false;
        foreach ($expected as $candidate) {
            $cmp = self::typedCompare($actual, $candidate, $type, $context);
            if ($cmp['error'] !== null) {
                return ConditionResult::fail(array_merge($warnings, [$cmp['error']]));
            }
            if ($cmp['order'] === 0) {
                $member = true;
                break;
            }
        }

        return new ConditionResult($negate ? ! $member : $member, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function opChanged(
        string $field,
        mixed $actual,
        string $type,
        ConditionContext $context,
        array $warnings,
    ): ConditionResult {
        $oldKey = $field !== '' ? $field : '__value';
        $old = array_key_exists($oldKey, $context->oldValues)
            ? $context->oldValues[$oldKey]
            : null;

        if ($actual === null && $old === null) {
            return new ConditionResult(false, $warnings);
        }

        if ($actual === null || $old === null) {
            return new ConditionResult(true, $warnings);
        }

        $cmp = self::typedCompare($actual, $old, $type, $context);
        if ($cmp['error'] !== null) {
            return ConditionResult::fail(array_merge($warnings, [$cmp['error']]));
        }

        return new ConditionResult($cmp['order'] !== 0, $warnings);
    }

    /**
     * @return array{order: int, error: ?string}  order is -1/0/1 like <=> 
     */
    private static function typedCompare(
        mixed $a,
        mixed $b,
        string $type,
        ConditionContext $context,
    ): array {
        return match ($type) {
            'boolean' => self::compareBoolean($a, $b),
            'number' => self::compareNumber($a, $b),
            'money' => self::compareMoney($a, $b),
            'date' => self::compareDate($a, $b, $context, false),
            'datetime' => self::compareDate($a, $b, $context, true),
            'select' => self::compareSelect($a, $b),
            'multiselect' => self::compareMultiselectEquality($a, $b),
            default => self::compareText($a, $b),
        };
    }

    /** @return array{order: int, error: ?string} */
    private static function compareText(mixed $a, mixed $b): array
    {
        $sa = self::castString($a);
        $sb = self::castString($b);
        if ($sa === null || $sb === null) {
            return ['order' => 0, 'error' => 'text cast failed'];
        }

        return ['order' => $sa <=> $sb, 'error' => null];
    }

    /** @return array{order: int, error: ?string} */
    private static function compareBoolean(mixed $a, mixed $b): array
    {
        $ba = self::castBool($a);
        $bb = self::castBool($b);
        if ($ba === null || $bb === null) {
            return ['order' => 0, 'error' => 'boolean cast failed'];
        }

        return ['order' => $ba <=> $bb, 'error' => null];
    }

    /** @return array{order: int, error: ?string} */
    private static function compareNumber(mixed $a, mixed $b): array
    {
        $sa = self::castDecimal($a);
        $sb = self::castDecimal($b);
        if ($sa === null || $sb === null) {
            return ['order' => 0, 'error' => 'number cast failed'];
        }

        return ['order' => BillingMath::cmp($sa, $sb), 'error' => null];
    }

    /** @return array{order: int, error: ?string} */
    private static function compareMoney(mixed $a, mixed $b): array
    {
        $sa = self::castDecimal($a);
        $sb = self::castDecimal($b);
        if ($sa === null || $sb === null) {
            return ['order' => 0, 'error' => 'money cast failed'];
        }

        return ['order' => BillingMath::cmp($sa, $sb), 'error' => null];
    }

    /** @return array{order: int, error: ?string} */
    private static function compareDate(mixed $a, mixed $b, ConditionContext $context, bool $asDateTime): array
    {
        $ca = self::castDate($a, $context->timezone, $asDateTime);
        $cb = self::castDate($b, $context->timezone, $asDateTime);
        if ($ca === null || $cb === null) {
            return ['order' => 0, 'error' => ($asDateTime ? 'datetime' : 'date').' cast failed'];
        }

        if ($ca->equalTo($cb)) {
            return ['order' => 0, 'error' => null];
        }

        return ['order' => $ca->lessThan($cb) ? -1 : 1, 'error' => null];
    }

    /** @return array{order: int, error: ?string} */
    private static function compareSelect(mixed $a, mixed $b): array
    {
        if (is_bool($a) || is_bool($b) || is_array($a) || is_array($b)) {
            return ['order' => 0, 'error' => 'select cast failed'];
        }

        return ['order' => ((string) $a) <=> ((string) $b), 'error' => null];
    }

    /** @return array{order: int, error: ?string} */
    private static function compareMultiselectEquality(mixed $a, mixed $b): array
    {
        if (! is_array($a) || ! is_array($b)) {
            return ['order' => 0, 'error' => 'multiselect cast failed'];
        }

        $na = array_map('strval', array_values($a));
        $nb = array_map('strval', array_values($b));
        sort($na);
        sort($nb);

        return ['order' => $na === $nb ? 0 : 1, 'error' => null];
    }

    private static function castString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function castBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return null;
    }

    private static function castDecimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return sprintf('%.8F', $value);
        }

        return null;
    }

    private static function castDate(mixed $value, string $timezone, bool $asDateTime): ?CarbonImmutable
    {
        try {
            if ($value instanceof CarbonImmutable) {
                $dt = $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $dt = CarbonImmutable::instance($value);
            } elseif (is_string($value) && $value !== '') {
                $dt = CarbonImmutable::parse($value, $timezone);
            } else {
                return null;
            }

            if ($asDateTime) {
                return $dt->utc();
            }

            return $dt->timezone($timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
