<?php

declare(strict_types=1);

namespace App\Support\Attributes;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Employee;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared value-normalization + persistence for custom attribute values.
 * Used by both AttributeValueController::upsert() and the copilot's
 * SetCustomProperty tool, so the two can never drift on validation rules.
 */
final class AttributeValueUpserter
{
    public static function upsert(
        AttributeDefinition $definition,
        Model $entity,
        AttributeEntityType $type,
        mixed $value,
        ?Employee $actor,
    ): ?AttributeValue {
        $normalized = self::normalizeValue($definition, $value);

        return DB::transaction(function () use ($definition, $entity, $type, $normalized, $actor) {
            $attributeValue = AttributeValue::query()->firstOrNew([
                'definition_id' => $definition->id,
                'entity_id' => $entity->getKey(),
            ]);

            $old = $attributeValue->exists ? self::scalarForLog($definition->type, $attributeValue) : null;

            $attributeValue->fill([
                'value_text' => null,
                'value_number' => null,
                'value_date' => null,
                'value_boolean' => null,
                'value_option_id' => null,
            ]);

            if ($normalized['cleared']) {
                if ($attributeValue->exists) {
                    $attributeValue->options()->sync([]);
                    $attributeValue->delete();

                    RecordsActivity::log(
                        $type->activityChannel(),
                        'attribute.value.cleared',
                        $entity,
                        [
                            'definition_id' => $definition->id,
                            'key' => $definition->key,
                            'old' => $old,
                            'new' => null,
                        ],
                        $actor,
                    );
                }

                return null;
            }

            $attributeValue->fill($normalized['columns']);
            $attributeValue->save();

            if ($definition->type === AttributeType::Multiselect) {
                $attributeValue->options()->sync($normalized['option_ids']);
            } else {
                $attributeValue->options()->sync([]);
            }

            $attributeValue = $attributeValue->fresh()->load(['definition.options', 'options']);
            $new = self::scalarForLog($definition->type, $attributeValue);

            RecordsActivity::log(
                $type->activityChannel(),
                'attribute.value.updated',
                $entity,
                [
                    'definition_id' => $definition->id,
                    'key' => $definition->key,
                    'old' => $old,
                    'new' => $new,
                ],
                $actor,
            );

            return $attributeValue;
        });
    }

    /**
     * @return array{cleared: bool, columns: array<string, mixed>, option_ids: list<int>}
     */
    private static function normalizeValue(AttributeDefinition $definition, mixed $value): array
    {
        $empty = $value === null || $value === '' || $value === [];

        if ($empty) {
            if ($definition->is_required) {
                throw ValidationException::withMessages([
                    'value' => ['This attribute is required.'],
                ]);
            }

            return ['cleared' => true, 'columns' => [], 'option_ids' => []];
        }

        $optionIds = $definition->options->pluck('id')->all();

        return match ($definition->type) {
            AttributeType::Text => [
                'cleared' => false,
                'columns' => ['value_text' => is_string($value) ? trim($value) : (string) $value],
                'option_ids' => [],
            ],
            AttributeType::Number => [
                'cleared' => false,
                'columns' => ['value_number' => self::assertNumeric($value)],
                'option_ids' => [],
            ],
            AttributeType::Date => [
                'cleared' => false,
                'columns' => ['value_date' => self::assertDate($value)],
                'option_ids' => [],
            ],
            AttributeType::Boolean => [
                'cleared' => false,
                'columns' => ['value_boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value],
                'option_ids' => [],
            ],
            AttributeType::Select => [
                'cleared' => false,
                'columns' => ['value_option_id' => self::assertOptionId($value, $optionIds)],
                'option_ids' => [],
            ],
            AttributeType::Multiselect => [
                'cleared' => false,
                'columns' => [],
                'option_ids' => self::assertOptionIds($value, $optionIds),
            ],
        };
    }

    private static function assertNumeric(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'value' => ['The value must be a number.'],
            ]);
        }

        return (string) $value;
    }

    private static function assertDate(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([
                'value' => ['The value must be a date (Y-m-d).'],
            ]);
        }

        return $value;
    }

    /** @param  list<int>  $allowed */
    private static function assertOptionId(mixed $value, array $allowed): int
    {
        $id = (int) $value;

        if (! in_array($id, $allowed, true)) {
            throw ValidationException::withMessages([
                'value' => ['The selected option is invalid.'],
            ]);
        }

        return $id;
    }

    /**
     * @param  list<int>  $allowed
     * @return list<int>
     */
    private static function assertOptionIds(mixed $value, array $allowed): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'value' => ['The value must be an array of option ids.'],
            ]);
        }

        $ids = array_values(array_unique(array_map('intval', $value)));

        foreach ($ids as $id) {
            if (! in_array($id, $allowed, true)) {
                throw ValidationException::withMessages([
                    'value' => ['One or more selected options are invalid.'],
                ]);
            }
        }

        return $ids;
    }

    private static function scalarForLog(AttributeType $type, AttributeValue $value): mixed
    {
        return match ($type) {
            AttributeType::Text => $value->value_text,
            AttributeType::Number => $value->value_number !== null ? (string) $value->value_number : null,
            AttributeType::Date => $value->value_date?->format('Y-m-d'),
            AttributeType::Boolean => $value->value_boolean,
            AttributeType::Select => $value->value_option_id,
            AttributeType::Multiselect => $value->relationLoaded('options')
                ? $value->options->pluck('id')->values()->all()
                : $value->options()->pluck('attribute_options.id')->all(),
        };
    }
}
