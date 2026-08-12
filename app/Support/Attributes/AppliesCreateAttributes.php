<?php

declare(strict_types=1);

namespace App\Support\Attributes;

use App\Enums\AttributeEntityType;
use App\Models\AttributeDefinition;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Validates and persists custom attribute values sent on entity create.
 * Enforces that every active required definition for the entity type is present
 * and non-empty, then upserts via {@see AttributeValueUpserter}.
 */
final class AppliesCreateAttributes
{
    /**
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'attributes' => ['nullable', 'array'],
            'attributes.*.definition_id' => ['required', 'integer', 'exists:attribute_definitions,id'],
            'attributes.*.value' => ['present'],
        ];
    }

    /**
     * @param  list<array{definition_id: int|string, value: mixed}>  $attributes
     */
    public static function apply(
        AttributeEntityType $type,
        Model $entity,
        array $attributes,
        ?Employee $actor,
    ): void {
        $required = AttributeDefinition::query()
            ->with('options')
            ->active()
            ->where('entity_type', $type)
            ->where('is_required', true)
            ->get()
            ->keyBy('id');

        /** @var array<int, array{index: int, value: mixed}> $byDefinitionId */
        $byDefinitionId = [];
        foreach ($attributes as $index => $row) {
            $definitionId = (int) $row['definition_id'];
            $byDefinitionId[$definitionId] = [
                'index' => (int) $index,
                'value' => $row['value'],
            ];
        }

        $errors = [];
        foreach ($required as $definitionId => $definition) {
            if (! array_key_exists($definitionId, $byDefinitionId)) {
                $errors["attributes.{$definitionId}"] = ['This attribute is required.'];

                continue;
            }

            if (self::isEmpty($byDefinitionId[$definitionId]['value'])) {
                $errors["attributes.{$definitionId}"] = ['This attribute is required.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($byDefinitionId === []) {
            return;
        }

        $definitions = AttributeDefinition::query()
            ->with('options')
            ->whereIn('id', array_keys($byDefinitionId))
            ->get()
            ->keyBy('id');

        foreach ($byDefinitionId as $definitionId => $payload) {
            /** @var AttributeDefinition|null $definition */
            $definition = $definitions->get($definitionId);

            if ($definition === null) {
                throw ValidationException::withMessages([
                    "attributes.{$payload['index']}.definition_id" => ['The selected attribute definition is invalid.'],
                ]);
            }

            if ($definition->entity_type !== $type || $definition->isArchived()) {
                throw ValidationException::withMessages([
                    "attributes.{$payload['index']}.definition_id" => ['Attribute definition entity type mismatch.'],
                ]);
            }

            try {
                AttributeValueUpserter::upsert(
                    $definition,
                    $entity,
                    $type,
                    $payload['value'],
                    $actor,
                );
            } catch (ValidationException $e) {
                $remapped = [];
                foreach ($e->errors() as $messages) {
                    $remapped["attributes.{$definitionId}"] = $messages;
                }

                throw ValidationException::withMessages($remapped);
            }
        }
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
