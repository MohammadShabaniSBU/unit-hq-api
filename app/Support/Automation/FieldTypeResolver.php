<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Support\Filtering\AttributeFieldResolver;
use App\Support\Filtering\FilterableFields;

/**
 * Resolves a condition field key to an evaluator type string.
 *
 * @see docs/automation-conditions.md Rule 1 / Rule 6
 */
final class FieldTypeResolver
{
    /** @var list<string> */
    public const MONEY_KEYS = [
        'amount',
        'unit_amount',
        'tax_amount',
        'total_amount',
        'balance',
        'deposit_amount',
        'overdue_base',
    ];

    /**
     * @return array{type: string, warning: ?string}
     *         type is one of text|number|money|date|datetime|boolean|select|multiselect
     *         warning set when attr is deleted/unknown
     */
    public static function resolve(string $field, ConditionContext $context): array
    {
        if ($field === '' || $field === '__value') {
            return ['type' => 'text', 'warning' => null];
        }

        if ($context->fieldTypes !== null && array_key_exists($field, $context->fieldTypes)) {
            return ['type' => $context->fieldTypes[$field], 'warning' => null];
        }

        if (in_array($field, self::MONEY_KEYS, true)) {
            return ['type' => 'money', 'warning' => null];
        }

        $attrId = AttributeFieldResolver::parseDefinitionId($field);
        if ($attrId !== null) {
            if ($context->entityType === null) {
                return ['type' => 'text', 'warning' => "Unknown attribute [{$field}]: no entity type in context"];
            }

            $definition = AttributeFieldResolver::resolve($context->entityType, $attrId);
            if ($definition === null) {
                return ['type' => 'text', 'warning' => "Unknown or deleted attribute [{$field}]"];
            }

            return ['type' => self::fromAttributeType($definition->type), 'warning' => null];
        }

        if ($context->entityType !== null) {
            $triggerable = TriggerableFields::find($context->entityType, $field);
            if ($triggerable !== null) {
                return ['type' => self::normalizeNativeType($field, $triggerable->type), 'warning' => null];
            }

            if (AttributeEntityType::tryFrom($context->entityType) !== null) {
                $native = FilterableFields::find($context->entityType, $field);
                if ($native !== null) {
                    return ['type' => self::normalizeNativeType($field, $native->type), 'warning' => null];
                }
            }
        }

        return ['type' => 'text', 'warning' => null];
    }

    private static function fromAttributeType(AttributeType $type): string
    {
        return match ($type) {
            AttributeType::Text => 'text',
            AttributeType::Number => 'number',
            AttributeType::Date => 'date',
            AttributeType::Boolean => 'boolean',
            AttributeType::Select => 'select',
            AttributeType::Multiselect => 'multiselect',
        };
    }

    private static function normalizeNativeType(string $key, string $type): string
    {
        if ($type === 'email') {
            return 'text';
        }

        if ($type === 'date' && str_ends_with($key, '_at')) {
            return 'datetime';
        }

        if (in_array($key, self::MONEY_KEYS, true)) {
            return 'money';
        }

        return $type;
    }
}
