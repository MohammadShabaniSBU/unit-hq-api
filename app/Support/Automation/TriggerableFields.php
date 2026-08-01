<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutopayAttemptStatus;
use App\Enums\PaymentMethod;
use App\Support\Filtering\FilterableField;
use App\Support\Filtering\FilterOperators;
use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * Whitelist of condition/property fields for automation trigger object types
 * that are not AttributeEntityType (billing surface). Same idiom as FilterableFields.
 */
final class TriggerableFields
{
    /** @return list<string> */
    public static function objectTypes(): array
    {
        return ['delinquency', 'autopay_attempt', 'payment'];
    }

    public static function supports(string $objectType): bool
    {
        return in_array($objectType, self::objectTypes(), true);
    }

    /**
     * @return list<FilterableField>
     */
    public static function for(string $objectType): array
    {
        return match ($objectType) {
            'delinquency' => self::delinquency(),
            'autopay_attempt' => self::autopayAttempt(),
            'payment' => self::payment(),
            default => throw new InvalidArgumentException("Unknown triggerable object type [{$objectType}]."),
        };
    }

    public static function find(string $objectType, string $key): ?FilterableField
    {
        if (! self::supports($objectType)) {
            return null;
        }

        foreach (self::for($objectType) as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<array{key: string, label: string, type: string, operators: list<string>, custom?: true, options?: list<array{value: string|int|bool, label: string}>}> */
    public static function schema(string $objectType): array
    {
        return array_map(
            fn (FilterableField $field): array => $field->toSchemaArray(),
            self::for($objectType),
        );
    }

    /** @return list<FilterableField> */
    private static function delinquency(): array
    {
        return [
            self::number('days_overdue', 'Days overdue'),
            self::money('overdue_base', 'Overdue base'),
            self::number('delinquency_policy_id', 'Delinquency policy'),
            self::number('site_id', 'Site'),
            self::date('cured_on', 'Cured on'),
        ];
    }

    /** @return list<FilterableField> */
    private static function autopayAttempt(): array
    {
        return [
            self::select('status', 'Status', self::enumOptions(AutopayAttemptStatus::cases())),
            self::text('failure_code', 'Failure code'),
            self::text('decline_code', 'Decline code'),
        ];
    }

    /** @return list<FilterableField> */
    private static function payment(): array
    {
        return [
            self::money('amount', 'Amount'),
            self::select('method', 'Method', self::enumOptions(PaymentMethod::cases())),
        ];
    }

    private static function text(string $key, string $label): FilterableField
    {
        return self::field($key, $label, 'text');
    }

    private static function number(string $key, string $label): FilterableField
    {
        return self::field($key, $label, 'number');
    }

    private static function money(string $key, string $label): FilterableField
    {
        return self::field($key, $label, 'number');
    }

    private static function date(string $key, string $label): FilterableField
    {
        return self::field($key, $label, 'date');
    }

    /**
     * @param  list<array{value: string|int|bool, label: string}>  $options
     */
    private static function select(string $key, string $label, array $options): FilterableField
    {
        return self::field($key, $label, 'select', $options);
    }

    /**
     * @param  list<array{value: string|int|bool, label: string}>|null  $options
     */
    private static function field(string $key, string $label, string $type, ?array $options = null): FilterableField
    {
        return new FilterableField(
            key: $key,
            label: $label,
            type: $type,
            operators: FilterOperators::forType($type),
            options: $options,
            column: $key,
        );
    }

    /**
     * @param  list<UnitEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function enumOptions(array $cases): array
    {
        return array_map(function (UnitEnum $case) {
            $value = $case instanceof BackedEnum ? (string) $case->value : $case->name;

            return [
                'value' => $value,
                'label' => ucfirst(str_replace('_', ' ', $value)),
            ];
        }, $cases);
    }
}
