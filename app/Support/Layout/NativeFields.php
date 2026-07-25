<?php

declare(strict_types=1);

namespace App\Support\Layout;

use App\Enums\AttributeEntityType;
use InvalidArgumentException;

/**
 * Registry of native overview fields per entity type.
 * Default layout migration inserts use {@see defaultLayoutKeys()}.
 */
final class NativeFields
{
    /**
     * @return list<NativeField>
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

    public static function find(AttributeEntityType|string $entityType, string $key): ?NativeField
    {
        foreach (self::for($entityType) as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public static function assertExists(AttributeEntityType|string $entityType, string $key): NativeField
    {
        $field = self::find($entityType, $key);

        if ($field === null) {
            throw new InvalidArgumentException("Unknown native field [{$key}] for entity [{$entityType}].");
        }

        return $field;
    }

    /**
     * Ordered keys inserted into the default system "details" card for each entity.
     *
     * @return list<string>
     */
    public static function defaultLayoutKeys(AttributeEntityType|string $entityType): array
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return match ($type) {
            AttributeEntityType::Contact => [
                'first_name',
                'last_name',
                'email',
                'status',
                'source',
                'source_detail',
            ],
            AttributeEntityType::Deal => [
                'status',
                'expected_move_in',
                'expected_stay_length',
                'expected_stay_period',
                'storage_reason',
                'desired_size',
                'desired_unit_class_id',
            ],
            AttributeEntityType::Offer => [
                'status',
                'expires_at',
                'sent_at',
                'first_viewed_at',
                'accepted_at',
            ],
            AttributeEntityType::Reservation => [
                'status',
                'unit_id',
                'expires_at',
            ],
            AttributeEntityType::Unit => [
                'site_id',
                'unit_class_id',
                'unit_number',
                'actual_width',
                'actual_depth',
                'actual_height',
                'note',
                'enabled',
            ],
            AttributeEntityType::Contract => [
                'start_date',
                'end_date',
                'status',
                'signed_at',
            ],
        };
    }

    public static function defaultGroupKey(AttributeEntityType|string $entityType): string
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return $type->value.'_details';
    }

    public static function defaultGroupLabel(AttributeEntityType|string $entityType): string
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return match ($type) {
            AttributeEntityType::Contact => 'Contact details',
            AttributeEntityType::Deal => 'Deal details',
            AttributeEntityType::Offer => 'Offer details',
            AttributeEntityType::Reservation => 'Reservation details',
            AttributeEntityType::Unit => 'Unit details',
            AttributeEntityType::Contract => 'Contract details',
        };
    }

    /** @return list<NativeField> */
    private static function contact(): array
    {
        return [
            new NativeField('first_name', 'First name', 'text', required: true),
            new NativeField('last_name', 'Last name', 'text', required: true),
            new NativeField('email', 'Email', 'email'),
            new NativeField('status', 'Status', 'select', optionsSource: 'contact_statuses'),
            new NativeField('source', 'Source', 'select', optionsSource: 'contact_sources'),
            new NativeField('source_detail', 'Source detail', 'text'),
        ];
    }

    /** @return list<NativeField> */
    private static function deal(): array
    {
        return [
            new NativeField('status', 'Status', 'select', required: true, optionsSource: 'deal_statuses'),
            new NativeField('expected_move_in', 'Expected move-in', 'date'),
            new NativeField('expected_stay_length', 'Expected stay length', 'number'),
            new NativeField('expected_stay_period', 'Expected stay period', 'select', optionsSource: 'stay_periods'),
            new NativeField('storage_reason', 'Storage reason', 'select', optionsSource: 'storage_reasons'),
            new NativeField('desired_size', 'Desired size', 'number'),
            new NativeField('desired_unit_class_id', 'Desired unit class', 'select', optionsSource: 'unit_classes'),
        ];
    }

    /** @return list<NativeField> */
    private static function offer(): array
    {
        return [
            new NativeField('status', 'Status', 'select', optionsSource: 'offer_statuses'),
            new NativeField('expires_at', 'Expires at', 'date', editable: false),
            new NativeField('sent_at', 'Sent at', 'date', editable: false),
            new NativeField('first_viewed_at', 'First viewed at', 'date', editable: false),
            new NativeField('accepted_at', 'Accepted at', 'date', editable: false),
        ];
    }

    /** @return list<NativeField> */
    private static function reservation(): array
    {
        return [
            new NativeField('status', 'Status', 'select', editable: false, optionsSource: 'reservation_statuses'),
            new NativeField('unit_id', 'Unit', 'select', editable: false, optionsSource: 'units'),
            new NativeField('expires_at', 'Expires at', 'date', editable: false),
        ];
    }

    /** @return list<NativeField> */
    private static function unit(): array
    {
        return [
            new NativeField('site_id', 'Site', 'select', required: true, optionsSource: 'sites'),
            new NativeField('unit_class_id', 'Unit class', 'select', required: true, optionsSource: 'unit_classes'),
            new NativeField('unit_number', 'Unit number', 'text', required: true),
            new NativeField('actual_width', 'Width', 'number'),
            new NativeField('actual_depth', 'Depth', 'number'),
            new NativeField('actual_height', 'Height', 'number'),
            new NativeField('note', 'Note', 'text'),
            new NativeField('enabled', 'Enabled', 'boolean'),
        ];
    }

    /** @return list<NativeField> */
    private static function contract(): array
    {
        return [
            new NativeField('start_date', 'Start date', 'date'),
            new NativeField('end_date', 'End date', 'date'),
            new NativeField('status', 'Status', 'select', optionsSource: 'contract_statuses'),
            new NativeField('signed_at', 'Signed at', 'date'),
        ];
    }
}
