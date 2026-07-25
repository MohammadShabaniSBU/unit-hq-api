<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Http\Resources\AttributeValueResource;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Unit;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttributeValueController extends Controller
{
    public function index(string $entityType, int $entityId): JsonResponse
    {
        $type = $this->resolveEntityType($entityType);
        $this->resolveEntity($type, $entityId);

        $definitionIds = AttributeDefinition::query()
            ->where('entity_type', $type)
            ->pluck('id');

        $values = AttributeValue::query()
            ->with(['definition.options', 'options'])
            ->where('entity_id', $entityId)
            ->whereIn('definition_id', $definitionIds)
            ->get();

        return $this->success(
            AttributeValueResource::collection($values)->resolve(),
            'Attribute values retrieved successfully.',
        );
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', Rule::enum(AttributeEntityType::class)],
            'entity_id' => ['required', 'integer'],
            'definition_id' => ['required', 'integer', 'exists:attribute_definitions,id'],
            'value' => ['present'],
        ]);

        $type = AttributeEntityType::from($validated['entity_type']);
        $entity = $this->resolveEntity($type, (int) $validated['entity_id']);
        $definition = AttributeDefinition::query()
            ->with('options')
            ->findOrFail((int) $validated['definition_id']);

        if ($definition->entity_type !== $type) {
            throw ValidationException::withMessages([
                'definition_id' => ['Attribute definition entity type mismatch.'],
            ]);
        }

        $normalized = $this->normalizeValue($definition, $validated['value']);

        $result = DB::transaction(function () use ($definition, $entity, $type, $normalized, $request) {
            $value = AttributeValue::query()->firstOrNew([
                'definition_id' => $definition->id,
                'entity_id' => $entity->getKey(),
            ]);

            $old = $value->exists ? $this->scalarForLog($definition->type, $value) : null;

            $value->fill([
                'value_text' => null,
                'value_number' => null,
                'value_date' => null,
                'value_boolean' => null,
                'value_option_id' => null,
            ]);

            if ($normalized['cleared']) {
                if ($value->exists) {
                    $value->options()->sync([]);
                    $value->delete();

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
                        $request->user(),
                    );

                    return null;
                }

                return null;
            }

            $value->fill($normalized['columns']);
            $value->save();

            if ($definition->type === AttributeType::Multiselect) {
                $value->options()->sync($normalized['option_ids']);
            } else {
                $value->options()->sync([]);
            }

            $value = $value->fresh()->load(['definition.options', 'options']);
            $new = $this->scalarForLog($definition->type, $value);

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
                $request->user(),
            );

            return $value;
        });

        if ($result === null) {
            return $this->success(null, 'Attribute value cleared successfully.');
        }

        return $this->success(
            AttributeValueResource::make($result)->resolve(),
            'Attribute value saved successfully.',
        );
    }

    private function resolveEntityType(string $entityType): AttributeEntityType
    {
        return AttributeEntityType::tryFrom($entityType)
            ?? throw ValidationException::withMessages([
                'entityType' => ['Unsupported entity type.'],
            ]);
    }

    private function resolveEntity(AttributeEntityType $type, int $entityId): Model
    {
        $model = match ($type) {
            AttributeEntityType::Contact => Contact::query()->find($entityId),
            AttributeEntityType::Deal => Deal::query()->find($entityId),
            AttributeEntityType::Offer => Offer::query()->find($entityId),
            AttributeEntityType::Reservation => Reservation::query()->find($entityId),
            AttributeEntityType::Unit => Unit::query()->find($entityId),
            AttributeEntityType::Contract => Contract::query()->find($entityId),
        };

        if ($model === null) {
            throw ValidationException::withMessages([
                'entity_id' => ['Entity not found.'],
            ]);
        }

        return $model;
    }

    /**
     * @return array{cleared: bool, columns: array<string, mixed>, option_ids: list<int>}
     */
    private function normalizeValue(AttributeDefinition $definition, mixed $value): array
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
                'columns' => ['value_number' => $this->assertNumeric($value)],
                'option_ids' => [],
            ],
            AttributeType::Date => [
                'cleared' => false,
                'columns' => ['value_date' => $this->assertDate($value)],
                'option_ids' => [],
            ],
            AttributeType::Boolean => [
                'cleared' => false,
                'columns' => ['value_boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value],
                'option_ids' => [],
            ],
            AttributeType::Select => [
                'cleared' => false,
                'columns' => ['value_option_id' => $this->assertOptionId($value, $optionIds)],
                'option_ids' => [],
            ],
            AttributeType::Multiselect => [
                'cleared' => false,
                'columns' => [],
                'option_ids' => $this->assertOptionIds($value, $optionIds),
            ],
        };
    }

    private function assertNumeric(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'value' => ['The value must be a number.'],
            ]);
        }

        return (string) $value;
    }

    private function assertDate(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([
                'value' => ['The value must be a date (Y-m-d).'],
            ]);
        }

        return $value;
    }

    /** @param  list<int>  $allowed */
    private function assertOptionId(mixed $value, array $allowed): int
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
    private function assertOptionIds(mixed $value, array $allowed): array
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

    private function scalarForLog(AttributeType $type, AttributeValue $value): mixed
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
