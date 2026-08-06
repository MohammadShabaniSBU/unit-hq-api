<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use App\Enums\AttributeEntityType;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayPeriod;
use App\Enums\TaxIdType;
use App\Models\Offer;
use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * Whitelist of native filterable columns per entity type.
 */
final class FilterableFields
{
    /**
     * @return list<FilterableField>
     */
    public static function for(AttributeEntityType|string $entityType): array
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return match ($type) {
            AttributeEntityType::Contact => self::contact(),
            AttributeEntityType::Deal => self::deal(),
            AttributeEntityType::Offer => self::offer(),
            AttributeEntityType::Reservation => self::reservation(),
            AttributeEntityType::Unit => self::unit(),
            AttributeEntityType::Contract => self::contract(),
        };
    }

    public static function find(AttributeEntityType|string $entityType, string $key): ?FilterableField
    {
        foreach (self::for($entityType) as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public static function assertExists(AttributeEntityType|string $entityType, string $key): FilterableField
    {
        $field = self::find($entityType, $key);

        if ($field === null) {
            throw new InvalidArgumentException("Unknown filterable field [{$key}] for entity [{$entityType}].");
        }

        return $field;
    }

    /** @return list<FilterableField> */
    private static function contact(): array
    {
        return [
            self::text('first_name', 'First name'),
            self::text('last_name', 'Last name'),
            self::field('email', 'Email', 'email'),
            self::text('company', 'Company'),
            self::text('billing_name', 'Billing name'),
            self::text('tax_id', 'Tax ID'),
            self::select('tax_id_type', 'Tax ID type', self::enumOptions(TaxIdType::cases())),
            self::text('billing_city', 'Billing city'),
            self::text('billing_postal_code', 'Billing postal code'),
            self::text('billing_country_code', 'Billing country'),
            self::select('status', 'Status', self::enumOptions(ContactLifecycleStatus::cases())),
            self::date('created_at', 'Created'),
            self::number('assigned_to', 'Assigned to'),
        ];
    }

    /** @return list<FilterableField> */
    private static function deal(): array
    {
        return [
            self::select('status', 'Status', self::enumOptions(DealStatus::cases())),
            self::date('expected_move_in', 'Expected move-in'),
            self::number('expected_stay_length', 'Expected stay length'),
            self::select('expected_stay_period', 'Expected stay period', self::enumOptions(StayPeriod::cases())),
            self::number('desired_size', 'Desired size'),
            self::number('desired_unit_class_id', 'Desired unit class'),
            self::number('contact_id', 'Contact'),
            self::date('created_at', 'Created'),
        ];
    }

    /** @return list<FilterableField> */
    private static function offer(): array
    {
        return [
            self::select('status', 'Status', array_map(
                fn (string $status) => ['value' => $status, 'label' => ucfirst(str_replace('_', ' ', $status))],
                Offer::STATUSES,
            )),
            self::date('expires_at', 'Expires at'),
            self::date('sent_at', 'Sent at'),
            self::date('first_viewed_at', 'First viewed at'),
            self::date('accepted_at', 'Accepted at'),
            self::number('deal_id', 'Deal'),
            self::number('contact_id', 'Contact'),
            self::date('created_at', 'Created'),
        ];
    }

    /** @return list<FilterableField> */
    private static function reservation(): array
    {
        return [
            self::select('status', 'Status', self::enumOptions(ReservationStatus::cases())),
            self::number('unit_id', 'Unit'),
            self::number('contact_id', 'Contact'),
            self::number('deal_id', 'Deal'),
            self::date('expires_at', 'Expires at'),
            self::date('created_at', 'Created'),
        ];
    }

    /** @return list<FilterableField> */
    private static function unit(): array
    {
        return [
            self::number('site_id', 'Site'),
            self::number('unit_class_id', 'Unit class'),
            self::text('unit_number', 'Unit number'),
            self::number('actual_width', 'Width'),
            self::number('actual_depth', 'Depth'),
            self::number('actual_height', 'Height'),
            self::text('note', 'Note'),
            self::field('enabled', 'Enabled', 'boolean'),
            self::date('created_at', 'Created'),
        ];
    }

    /** @return list<FilterableField> */
    private static function contract(): array
    {
        return [
            self::date('start_date', 'Start date'),
            self::date('end_date', 'End date'),
            self::select('status', 'Status', self::enumOptions(ContractStatus::cases())),
            self::date('signed_at', 'Signed at'),
            self::number('contact_id', 'Contact'),
            self::number('deal_id', 'Deal'),
            self::date('created_at', 'Created'),
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
