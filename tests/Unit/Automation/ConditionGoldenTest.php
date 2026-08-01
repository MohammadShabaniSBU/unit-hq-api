<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Enums\ConditionSource;
use App\Support\Automation\ConditionContext;
use App\Support\Automation\ConditionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden matrix for docs/automation-conditions.md — each row cites a rule number.
 */
class ConditionGoldenTest extends TestCase
{
    /** @return array<string, string> */
    private static function catalog(): array
    {
        return [
            'text_f' => 'text',
            'num_f' => 'number',
            'money_f' => 'money',
            'date_f' => 'date',
            'dt_f' => 'datetime',
            'bool_f' => 'boolean',
            'sel_f' => 'select',
            'multi_f' => 'multiselect',
        ];
    }

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>, 2: array<string, mixed>, 3: bool, 4: bool, 5?: array<string, mixed>}>
     */
    public static function matrixProvider(): array
    {
        $rows = [];

        $add = function (
            string $name,
            int $rule,
            array $condition,
            array $values,
            bool $passed,
            bool $expectWarning,
            array $oldValues = [],
        ) use (&$rows): void {
            $rows[$name] = [$rule, $condition, $values, $passed, $expectWarning, $oldValues];
        };

        // --- Rule 1: type discipline ---
        $add('r1_text_equals', 1, self::c('text_f', 'equals', 'abc'), ['text_f' => 'abc'], true, false);
        $add('r1_text_neq', 1, self::c('text_f', 'not_equals', 'abc'), ['text_f' => 'abd'], true, false);
        $add('r1_num_gt_string_numeric', 1, self::c('num_f', 'greater_than', '9'), ['num_f' => '10'], true, false);
        $add('r1_num_lexical_trap', 1, self::c('num_f', 'greater_than', '9'), ['num_f' => '10'], true, false);
        $add('r1_bool_true', 1, self::c('bool_f', 'equals', true), ['bool_f' => '1'], true, false);
        $add('r1_bool_false', 1, self::c('bool_f', 'equals', false), ['bool_f' => '0'], true, false);
        $add('r1_bool_wrong', 1, self::c('bool_f', 'equals', true), ['bool_f' => 'yes'], false, true);
        $add('r1_select_eq', 1, self::c('sel_f', 'equals', 3), ['sel_f' => 3], true, false);
        $add('r1_select_str', 1, self::c('sel_f', 'equals', '3'), ['sel_f' => 3], true, false);
        $add('r1_date_eq', 1, self::c('date_f', 'equals', '2026-08-01'), ['date_f' => '2026-08-01'], true, false);
        $add('r1_date_gt', 1, self::c('date_f', 'greater_than', '2026-07-01'), ['date_f' => '2026-08-01'], true, false);
        $add('r1_dt_eq', 1, self::c('dt_f', 'equals', '2026-08-01T10:00:00Z'), ['dt_f' => '2026-08-01T10:00:00+00:00'], true, false);
        $add('r1_wrong_type_num', 1, self::c('num_f', 'greater_than', '5'), ['num_f' => 'nope'], false, true);
        $add('r1_wrong_type_date', 1, self::c('date_f', 'equals', '2026-08-01'), ['date_f' => 'not-a-date'], false, true);
        $add('r1_starts_with', 1, self::c('text_f', 'starts_with', 'ab'), ['text_f' => 'abc'], true, false);
        $add('r1_ends_with', 1, self::c('text_f', 'ends_with', 'bc'), ['text_f' => 'abc'], true, false);
        $add('r1_starts_on_num_warn', 1, self::c('num_f', 'starts_with', '1'), ['num_f' => '12'], false, true);

        // --- Rule 2: null / missing / empty ---
        $add('r2_null_equals_x', 2, self::c('text_f', 'equals', 'x'), ['text_f' => null], false, false);
        $add('r2_null_equals_null', 2, self::c('text_f', 'equals', null), ['text_f' => null], false, false);
        $add('r2_null_not_equals_x', 2, self::c('text_f', 'not_equals', 'x'), ['text_f' => null], false, false);
        $add('r2_null_not_equals_null', 2, self::c('text_f', 'not_equals', null), ['text_f' => null], false, false);
        $add('r2_missing_equals', 2, self::c('text_f', 'equals', 'x'), [], false, false);
        $add('r2_missing_not_equals', 2, self::c('text_f', 'not_equals', 'x'), [], false, false);
        $add('r2_empty_str_equals', 2, self::c('text_f', 'equals', ''), ['text_f' => ''], true, false);
        $add('r2_empty_str_not_null', 2, self::c('text_f', 'equals', null), ['text_f' => ''], false, false);
        $add('r2_is_empty_null', 2, self::c('text_f', 'is_empty', null), ['text_f' => null], true, false);
        $add('r2_is_empty_missing', 2, self::c('text_f', 'is_empty', null), [], true, false);
        $add('r2_is_empty_str', 2, self::c('text_f', 'is_empty', null), ['text_f' => ''], true, false);
        $add('r2_is_empty_arr', 2, self::c('multi_f', 'is_empty', null), ['multi_f' => []], true, false);
        $add('r2_is_not_empty', 2, self::c('text_f', 'is_not_empty', null), ['text_f' => 'x'], true, false);
        $add('r2_is_not_empty_null', 2, self::c('text_f', 'is_not_empty', null), ['text_f' => null], false, false);
        $add('r2_null_gt', 2, self::c('num_f', 'greater_than', '1'), ['num_f' => null], false, false);
        $add('r2_null_contains', 2, self::c('text_f', 'contains', 'a'), ['text_f' => null], false, false);
        $add('r2_value_null_expected', 2, self::c('text_f', 'equals', null), ['text_f' => 'x'], false, false);

        // --- Rule 3: collections ---
        $add('r3_in_hit', 3, self::c('sel_f', 'in', [1, 2, 3]), ['sel_f' => 2], true, false);
        $add('r3_in_miss', 3, self::c('sel_f', 'in', [1, 2, 3]), ['sel_f' => 9], false, false);
        $add('r3_in_empty_list', 3, self::c('sel_f', 'in', []), ['sel_f' => 1], false, false);
        $add('r3_not_in_empty_list', 3, self::c('sel_f', 'not_in', []), ['sel_f' => 1], true, false);
        $add('r3_not_in_hit', 3, self::c('sel_f', 'not_in', [1, 2]), ['sel_f' => 9], true, false);
        $add('r3_not_in_member', 3, self::c('sel_f', 'not_in', [1, 2]), ['sel_f' => 1], false, false);
        $add('r3_in_null_actual', 3, self::c('sel_f', 'in', [1]), ['sel_f' => null], false, false);
        $add('r3_not_in_null_actual', 3, self::c('sel_f', 'not_in', [1]), ['sel_f' => null], false, false);
        $add('r3_contains_substr', 3, self::c('text_f', 'contains', 'bc'), ['text_f' => 'abcd'], true, false);
        $add('r3_not_contains', 3, self::c('text_f', 'not_contains', 'zz'), ['text_f' => 'abcd'], true, false);
        $add('r3_contains_on_multi_warn', 3, self::c('multi_f', 'contains', '1'), ['multi_f' => [1, 2]], false, true);
        $add('r3_multi_in_overlap', 3, self::c('multi_f', 'in', [2, 9]), ['multi_f' => [1, 2]], true, false);
        $add('r3_multi_in_no_overlap', 3, self::c('multi_f', 'in', [8, 9]), ['multi_f' => [1, 2]], false, false);
        $add('r3_multi_not_in_overlap', 3, self::c('multi_f', 'not_in', [2]), ['multi_f' => [1, 2]], false, false);
        $add('r3_multi_not_in_clean', 3, self::c('multi_f', 'not_in', [8]), ['multi_f' => [1, 2]], true, false);
        $add('r3_in_not_list', 3, self::c('sel_f', 'in', 'oops'), ['sel_f' => 1], false, true);

        // --- Rule 4: nesting covered in nested_and_side_effect_free; leaf ops here ---
        $add('r4_and_both', 4, [
            'logic' => 'and',
            'conditions' => [
                self::c('text_f', 'equals', 'a'),
                self::c('num_f', 'equals', '1'),
            ],
        ], ['text_f' => 'a', 'num_f' => '1'], true, false);
        $add('r4_and_fail', 4, [
            'logic' => 'and',
            'conditions' => [
                self::c('text_f', 'equals', 'a'),
                self::c('num_f', 'equals', '2'),
            ],
        ], ['text_f' => 'a', 'num_f' => '1'], false, false);
        $add('r4_or_one', 4, [
            'logic' => 'or',
            'conditions' => [
                self::c('text_f', 'equals', 'z'),
                self::c('num_f', 'equals', '1'),
            ],
        ], ['text_f' => 'a', 'num_f' => '1'], true, false);
        $add('r4_not_true', 4, [
            'logic' => 'not',
            'conditions' => [self::c('text_f', 'equals', 'a')],
        ], ['text_f' => 'a'], false, false);
        $add('r4_not_false', 4, [
            'logic' => 'not',
            'conditions' => [self::c('text_f', 'equals', 'a')],
        ], ['text_f' => 'b'], true, false);
        $add('r4_empty_group', 4, ['logic' => 'and', 'conditions' => []], [], true, false);
        $add('r4_op_alias', 4, [
            'op' => 'and',
            'conditions' => [self::c('text_f', 'eq', 'a')],
        ], ['text_f' => 'a'], true, false);

        // --- Rule 5: source is call-site; evaluator accepts either ---
        $add('r5_snapshot_context_ok', 5, self::c('text_f', 'equals', 'snap'), ['text_f' => 'snap'], true, false);

        // --- Rule 6: money bcmath ---
        $add('r6_money_lt', 6, self::c('money_f', 'less_than', '10.00'), ['money_f' => '9.50'], true, false);
        $add('r6_money_lexical_trap', 6, self::c('money_f', 'less_than', '10.00'), ['money_f' => '9.50'], true, false);
        $add('r6_money_gt', 6, self::c('money_f', 'greater_than', '9.50'), ['money_f' => '10.00'], true, false);
        $add('r6_money_eq', 6, self::c('money_f', 'equals', '10.00'), ['money_f' => '10.00'], true, false);
        $add('r6_money_gte', 6, self::c('money_f', 'gte', '10.00'), ['money_f' => '10.00'], true, false);
        $add('r6_money_lte', 6, self::c('money_f', 'lte', '9.50'), ['money_f' => '9.50'], true, false);
        $add('r6_money_wrong', 6, self::c('money_f', 'equals', '10.00'), ['money_f' => 'ten'], false, true);
        $add('r6_money_null', 6, self::c('money_f', 'equals', '10.00'), ['money_f' => null], false, false);

        // --- changed ---
        $add('r2_changed_both_null', 2, self::c('text_f', 'changed', null), ['text_f' => null], false, false, ['text_f' => null]);
        $add('r2_changed_one_null', 2, self::c('text_f', 'changed', null), ['text_f' => 'a'], true, false, ['text_f' => null]);
        $add('r1_changed_diff', 1, self::c('text_f', 'changed', null), ['text_f' => 'b'], true, false, ['text_f' => 'a']);
        $add('r1_changed_same', 1, self::c('text_f', 'changed', null), ['text_f' => 'a'], false, false, ['text_f' => 'a']);

        // Expand: types × ops × value classes for volume (≥120)
        foreach (self::expansionRows() as $name => $row) {
            $rows[$name] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>, 2: array<string, mixed>, 3: bool, 4: bool, 5: array<string, mixed>}>
     */
    private static function expansionRows(): array
    {
        $rows = [];
        $i = 0;

        $cases = [
            // text
            ['text_f', 'equals', 'hello', ['text_f' => 'hello'], true, false, 1],
            ['text_f', 'equals', 'hello', ['text_f' => 'world'], false, false, 1],
            ['text_f', 'equals', 'hello', ['text_f' => null], false, false, 2],
            ['text_f', 'equals', 'hello', [], false, false, 2],
            ['text_f', 'equals', 'hello', ['text_f' => ''], false, false, 2],
            ['text_f', 'not_equals', 'hello', ['text_f' => 'world'], true, false, 1],
            ['text_f', 'not_equals', 'hello', ['text_f' => null], false, false, 2],
            ['text_f', 'contains', 'ell', ['text_f' => 'hello'], true, false, 3],
            ['text_f', 'contains', 'ell', ['text_f' => 'hi'], false, false, 3],
            ['text_f', 'contains', 'ell', ['text_f' => null], false, false, 2],
            ['text_f', 'contains', 'ell', [], false, false, 2],
            ['text_f', 'contains', 'ell', ['text_f' => ''], false, false, 3],
            ['text_f', 'not_contains', 'zz', ['text_f' => 'hello'], true, false, 3],
            ['text_f', 'starts_with', 'he', ['text_f' => 'hello'], true, false, 1],
            ['text_f', 'ends_with', 'lo', ['text_f' => 'hello'], true, false, 1],
            ['text_f', 'is_empty', null, ['text_f' => ''], true, false, 2],
            ['text_f', 'is_empty', null, ['text_f' => 'x'], false, false, 2],
            ['text_f', 'is_not_empty', null, ['text_f' => 'x'], true, false, 2],
            ['text_f', 'in', ['a', 'b'], ['text_f' => 'a'], true, false, 3],
            ['text_f', 'in', [], ['text_f' => 'a'], false, false, 3],
            ['text_f', 'not_in', [], ['text_f' => 'a'], true, false, 3],
            ['text_f', 'not_in', ['a'], ['text_f' => 'b'], true, false, 3],

            // number
            ['num_f', 'equals', '10', ['num_f' => '10'], true, false, 1],
            ['num_f', 'equals', '10', ['num_f' => '9'], false, false, 1],
            ['num_f', 'equals', '10', ['num_f' => null], false, false, 2],
            ['num_f', 'equals', '10', [], false, false, 2],
            ['num_f', 'equals', '10', ['num_f' => ''], false, true, 1],
            ['num_f', 'not_equals', '10', ['num_f' => '9'], true, false, 1],
            ['num_f', 'not_equals', '10', ['num_f' => null], false, false, 2],
            ['num_f', 'greater_than', '9', ['num_f' => '10'], true, false, 1],
            ['num_f', 'greater_than', '9', ['num_f' => '9'], false, false, 1],
            ['num_f', 'greater_than', '9', ['num_f' => null], false, false, 2],
            ['num_f', 'greater_than', '9', ['num_f' => 'x'], false, true, 1],
            ['num_f', 'less_than', '11', ['num_f' => '10'], true, false, 1],
            ['num_f', 'gte', '10', ['num_f' => '10'], true, false, 1],
            ['num_f', 'lte', '10', ['num_f' => '10'], true, false, 1],
            ['num_f', 'is_empty', null, ['num_f' => null], true, false, 2],
            ['num_f', 'in', ['1', '2', '10'], ['num_f' => '10'], true, false, 3],
            ['num_f', 'in', [], ['num_f' => '10'], false, false, 3],
            ['num_f', 'not_in', [], ['num_f' => '10'], true, false, 3],

            // money
            ['money_f', 'equals', '1.00', ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'equals', '1.00', ['money_f' => null], false, false, 2],
            ['money_f', 'equals', '1.00', [], false, false, 2],
            ['money_f', 'equals', '1.00', ['money_f' => ''], false, true, 6],
            ['money_f', 'equals', '1.00', ['money_f' => 'bad'], false, true, 6],
            ['money_f', 'greater_than', '0.99', ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'less_than', '1.01', ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'gte', '1.00', ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'lte', '1.00', ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'not_equals', '2.00', ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'not_equals', '2.00', ['money_f' => null], false, false, 2],
            ['money_f', 'in', ['1.00', '2.00'], ['money_f' => '1.00'], true, false, 6],
            ['money_f', 'in', [], ['money_f' => '1.00'], false, false, 3],
            ['money_f', 'is_empty', null, ['money_f' => null], true, false, 2],
            ['money_f', 'is_not_empty', null, ['money_f' => '1.00'], true, false, 2],

            // date
            ['date_f', 'equals', '2026-01-15', ['date_f' => '2026-01-15'], true, false, 1],
            ['date_f', 'equals', '2026-01-15', ['date_f' => null], false, false, 2],
            ['date_f', 'equals', '2026-01-15', [], false, false, 2],
            ['date_f', 'equals', '2026-01-15', ['date_f' => ''], false, true, 1],
            ['date_f', 'equals', '2026-01-15', ['date_f' => 'nope'], false, true, 1],
            ['date_f', 'greater_than', '2026-01-01', ['date_f' => '2026-01-15'], true, false, 1],
            ['date_f', 'less_than', '2026-02-01', ['date_f' => '2026-01-15'], true, false, 1],
            ['date_f', 'gte', '2026-01-15', ['date_f' => '2026-01-15'], true, false, 1],
            ['date_f', 'lte', '2026-01-15', ['date_f' => '2026-01-15'], true, false, 1],
            ['date_f', 'not_equals', '2026-01-01', ['date_f' => '2026-01-15'], true, false, 1],
            ['date_f', 'not_equals', '2026-01-01', ['date_f' => null], false, false, 2],
            ['date_f', 'is_empty', null, ['date_f' => null], true, false, 2],
            ['date_f', 'in', ['2026-01-15', '2026-01-16'], ['date_f' => '2026-01-15'], true, false, 3],

            // datetime
            ['dt_f', 'equals', '2026-01-15T12:00:00Z', ['dt_f' => '2026-01-15T12:00:00+00:00'], true, false, 1],
            ['dt_f', 'equals', '2026-01-15T12:00:00Z', ['dt_f' => null], false, false, 2],
            ['dt_f', 'equals', '2026-01-15T12:00:00Z', [], false, false, 2],
            ['dt_f', 'equals', '2026-01-15T12:00:00Z', ['dt_f' => 'bad'], false, true, 1],
            ['dt_f', 'greater_than', '2026-01-15T11:00:00Z', ['dt_f' => '2026-01-15T12:00:00Z'], true, false, 1],
            ['dt_f', 'less_than', '2026-01-15T13:00:00Z', ['dt_f' => '2026-01-15T12:00:00Z'], true, false, 1],
            ['dt_f', 'gte', '2026-01-15T12:00:00Z', ['dt_f' => '2026-01-15T12:00:00Z'], true, false, 1],
            ['dt_f', 'lte', '2026-01-15T12:00:00Z', ['dt_f' => '2026-01-15T12:00:00Z'], true, false, 1],
            ['dt_f', 'not_equals', '2026-01-15T11:00:00Z', ['dt_f' => '2026-01-15T12:00:00Z'], true, false, 1],
            ['dt_f', 'is_empty', null, [], true, false, 2],

            // boolean
            ['bool_f', 'equals', true, ['bool_f' => true], true, false, 1],
            ['bool_f', 'equals', true, ['bool_f' => false], false, false, 1],
            ['bool_f', 'equals', true, ['bool_f' => null], false, false, 2],
            ['bool_f', 'equals', true, [], false, false, 2],
            ['bool_f', 'equals', true, ['bool_f' => 'maybe'], false, true, 1],
            ['bool_f', 'not_equals', true, ['bool_f' => false], true, false, 1],
            ['bool_f', 'not_equals', true, ['bool_f' => null], false, false, 2],
            ['bool_f', 'is_empty', null, ['bool_f' => null], true, false, 2],
            ['bool_f', 'is_not_empty', null, ['bool_f' => false], true, false, 2],
            ['bool_f', 'equals', false, ['bool_f' => 'false'], true, false, 1],
            ['bool_f', 'equals', true, ['bool_f' => 1], true, false, 1],

            // select
            ['sel_f', 'equals', 5, ['sel_f' => 5], true, false, 1],
            ['sel_f', 'equals', 5, ['sel_f' => null], false, false, 2],
            ['sel_f', 'equals', 5, [], false, false, 2],
            ['sel_f', 'not_equals', 5, ['sel_f' => 6], true, false, 1],
            ['sel_f', 'not_equals', 5, ['sel_f' => null], false, false, 2],
            ['sel_f', 'in', [5, 6], ['sel_f' => 5], true, false, 3],
            ['sel_f', 'in', [], ['sel_f' => 5], false, false, 3],
            ['sel_f', 'not_in', [], ['sel_f' => 5], true, false, 3],
            ['sel_f', 'not_in', [5], ['sel_f' => 6], true, false, 3],
            ['sel_f', 'is_empty', null, ['sel_f' => null], true, false, 2],
            ['sel_f', 'is_not_empty', null, ['sel_f' => 5], true, false, 2],

            // multiselect
            ['multi_f', 'in', [1], ['multi_f' => [1, 2]], true, false, 3],
            ['multi_f', 'in', [9], ['multi_f' => [1, 2]], false, false, 3],
            ['multi_f', 'in', [], ['multi_f' => [1]], false, false, 3],
            ['multi_f', 'not_in', [], ['multi_f' => [1]], true, false, 3],
            ['multi_f', 'not_in', [9], ['multi_f' => [1, 2]], true, false, 3],
            ['multi_f', 'not_in', [1], ['multi_f' => [1, 2]], false, false, 3],
            ['multi_f', 'in', [1], ['multi_f' => null], false, false, 2],
            ['multi_f', 'in', [1], [], false, false, 2],
            ['multi_f', 'is_empty', null, ['multi_f' => []], true, false, 2],
            ['multi_f', 'is_not_empty', null, ['multi_f' => [1]], true, false, 2],
            ['multi_f', 'equals', [1, 2], ['multi_f' => [2, 1]], true, false, 1],
            ['multi_f', 'equals', [1], ['multi_f' => [1, 2]], false, false, 1],
            ['multi_f', 'contains', '1', ['multi_f' => [1]], false, true, 3],
        ];

        foreach ($cases as [$field, $op, $expected, $values, $passed, $warn, $rule]) {
            $i++;
            $rows["exp_{$i}_{$field}_{$op}"] = [
                $rule,
                self::c($field, $op, $expected),
                $values,
                $passed,
                $warn,
                [],
            ];
        }

        return $rows;
    }

    /**
     * @return array{field: string, operator: string, value: mixed}
     */
    private static function c(string $field, string $operator, mixed $value): array
    {
        return [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    #[DataProvider('matrixProvider')]
    public function test_matrix(
        int $rule,
        array $condition,
        array $values,
        bool $expectedPassed,
        bool $expectWarning,
        array $oldValues = [],
    ): void {
        $this->assertGreaterThan(0, $rule);

        $group = [
            'logic' => 'and',
            'conditions' => [$condition],
        ];

        // Nested group conditions already have logic
        if (isset($condition['logic']) || isset($condition['op'])) {
            $group = $condition;
        }

        $result = ConditionEvaluator::evaluate(
            $group,
            $values,
            new ConditionContext(
                source: ConditionSource::Snapshot,
                fieldTypes: self::catalog(),
                oldValues: $oldValues,
                timezone: 'UTC',
            ),
        );

        $this->assertSame(
            $expectedPassed,
            $result->passed,
            'rule '.$rule.' passed mismatch; warnings='.implode(';', $result->warnings),
        );

        if ($expectWarning) {
            $this->assertNotEmpty($result->warnings, 'rule '.$rule.' expected a warning');
        } else {
            $this->assertSame([], $result->warnings, 'rule '.$rule.' unexpected warnings');
        }
    }

    public function test_matrix_has_at_least_120_rows(): void
    {
        $this->assertGreaterThanOrEqual(120, count(self::matrixProvider()));
    }

    public function test_nested_and_side_effect_free(): void
    {
        // AND short-circuit: first false — second would warn (wrong-type) but must not run
        $andTree = [
            'logic' => 'and',
            'conditions' => [
                self::c('text_f', 'equals', 'nope'),
                self::c('num_f', 'greater_than', '1'),
            ],
        ];

        $andResult = ConditionEvaluator::evaluate(
            $andTree,
            ['text_f' => 'yes', 'num_f' => 'not-a-number'],
            new ConditionContext(
                source: ConditionSource::Snapshot,
                fieldTypes: self::catalog(),
            ),
        );

        $this->assertFalse($andResult->passed);
        $this->assertSame([], $andResult->warnings, 'AND short-circuit must not evaluate warning side');

        // OR short-circuit: first true — second would warn
        $orTree = [
            'logic' => 'or',
            'conditions' => [
                self::c('text_f', 'equals', 'yes'),
                self::c('num_f', 'greater_than', '1'),
            ],
        ];

        $orResult = ConditionEvaluator::evaluate(
            $orTree,
            ['text_f' => 'yes', 'num_f' => 'not-a-number'],
            new ConditionContext(
                source: ConditionSource::Snapshot,
                fieldTypes: self::catalog(),
            ),
        );

        $this->assertTrue($orResult->passed);
        $this->assertSame([], $orResult->warnings, 'OR short-circuit must not evaluate warning side');

        // 3-deep mixed AND/OR/NOT with null-semantics under NOT
        $deep = [
            'logic' => 'and',
            'conditions' => [
                [
                    'logic' => 'or',
                    'conditions' => [
                        self::c('text_f', 'equals', 'a'),
                        [
                            'logic' => 'not',
                            'conditions' => [
                                self::c('num_f', 'equals', '99'),
                            ],
                        ],
                    ],
                ],
                [
                    'logic' => 'not',
                    'conditions' => [
                        // null equals x is false → NOT false → true
                        self::c('money_f', 'equals', '1.00'),
                    ],
                ],
            ],
        ];

        $deepResult = ConditionEvaluator::evaluate(
            $deep,
            ['text_f' => 'z', 'num_f' => '1', 'money_f' => null],
            new ConditionContext(
                source: ConditionSource::Snapshot,
                fieldTypes: self::catalog(),
            ),
        );

        $this->assertTrue($deepResult->passed);
    }

    public function test_deleted_attr_warns_and_false(): void
    {
        $result = ConditionEvaluator::evaluate(
            [
                'logic' => 'and',
                'conditions' => [
                    ['field' => 'attr:999999', 'operator' => 'equals', 'value' => 'x'],
                ],
            ],
            ['attr:999999' => 'x'],
            new ConditionContext(
                source: ConditionSource::Snapshot,
                entityType: 'contact',
            ),
        );

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->warnings);
    }
}
