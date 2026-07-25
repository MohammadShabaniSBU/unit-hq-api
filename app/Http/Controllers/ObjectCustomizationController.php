<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\LayoutFieldType;
use App\Enums\LogChannel;
use App\Http\Resources\AttributeDefinitionResource;
use App\Http\Resources\AttributeGroupResource;
use App\Http\Resources\LayoutFieldResource;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\LayoutField;
use App\Support\Layout\NativeFields;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObjectCustomizationController extends Controller
{
    public function show(string $entityType): JsonResponse
    {
        $type = $this->resolveEntityType($entityType);

        $groups = AttributeGroup::query()
            ->where('entity_type', $type)
            ->with(['fields.attributeDefinition.options'])
            ->orderBy('display_order')
            ->get();

        $placedNativeKeys = $groups
            ->flatMap(fn (AttributeGroup $group) => $group->fields)
            ->where('field_type', LayoutFieldType::Native)
            ->pluck('native_field_key')
            ->filter()
            ->all();

        $placedDefinitionIds = $groups
            ->flatMap(fn (AttributeGroup $group) => $group->fields)
            ->where('field_type', LayoutFieldType::Attribute)
            ->pluck('attribute_definition_id')
            ->filter()
            ->all();

        $availableNative = collect(NativeFields::for($type))
            ->reject(fn ($field) => in_array($field->key, $placedNativeKeys, true))
            ->map(fn ($field) => $field->toArray())
            ->values()
            ->all();

        $availableAttributes = AttributeDefinition::query()
            ->with('options')
            ->active()
            ->where('entity_type', $type)
            ->whereNotIn('id', $placedDefinitionIds ?: [0])
            ->orderBy('display_order')
            ->orderBy('label')
            ->get();

        return $this->success([
            'entity_type' => $type->value,
            'groups' => AttributeGroupResource::collection($groups)->resolve(),
            'available' => [
                'native' => $availableNative,
                'attributes' => AttributeDefinitionResource::collection($availableAttributes)->resolve(),
            ],
        ], 'Object customization retrieved successfully.');
    }

    public function storeGroup(Request $request, string $entityType): JsonResponse
    {
        $type = $this->resolveEntityType($entityType);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'key' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('attribute_groups', 'key')->where(
                    fn ($query) => $query->where('entity_type', $type->value),
                ),
            ],
        ]);

        $label = trim($validated['label']);
        $key = $validated['key'] ?? $this->uniqueGroupKey($type, $label);

        $maxOrder = (int) AttributeGroup::query()
            ->where('entity_type', $type)
            ->max('display_order');

        $group = DB::transaction(function () use ($type, $label, $key, $maxOrder, $request) {
            $group = AttributeGroup::query()->create([
                'entity_type' => $type,
                'key' => $key,
                'label' => $label,
                'display_order' => $maxOrder + 1,
                'is_system' => false,
            ]);

            RecordsActivity::log(
                LogChannel::Facility,
                'layout.group.created',
                $group,
                [
                    'entity_type' => $type->value,
                    'key' => $group->key,
                    'label' => $group->label,
                ],
                $request->user(),
            );

            return $group;
        });

        return $this->created(
            AttributeGroupResource::make($group->load('fields'))->resolve(),
            'Card created successfully.',
        );
    }

    public function updateGroup(Request $request, AttributeGroup $group): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        $group = DB::transaction(function () use ($validated, $group, $request) {
            if (array_key_exists('label', $validated)) {
                $group->update(['label' => trim($validated['label'])]);
            }

            RecordsActivity::log(
                LogChannel::Facility,
                'layout.group.updated',
                $group,
                [
                    'entity_type' => $group->entity_type->value,
                    'key' => $group->key,
                    'label' => $group->label,
                ],
                $request->user(),
            );

            return $group->fresh()->load(['fields.attributeDefinition.options']);
        });

        return $this->success(
            AttributeGroupResource::make($group)->resolve(),
            'Card updated successfully.',
        );
    }

    public function reorderGroups(Request $request, string $entityType): JsonResponse
    {
        $type = $this->resolveEntityType($entityType);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values($validated['ids']);

        $groups = AttributeGroup::query()
            ->where('entity_type', $type)
            ->whereIn('id', $ids)
            ->get();

        if ($groups->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['One or more cards do not belong to this entity.'],
            ]);
        }

        $existingIds = AttributeGroup::query()
            ->where('entity_type', $type)
            ->orderBy('display_order')
            ->pluck('id')
            ->all();

        if (count($ids) !== count($existingIds) || array_diff($existingIds, $ids) !== []) {
            throw ValidationException::withMessages([
                'ids' => ['ids must include every card for this entity exactly once.'],
            ]);
        }

        DB::transaction(function () use ($ids, $type, $request) {
            foreach ($ids as $index => $id) {
                AttributeGroup::query()->whereKey($id)->update(['display_order' => $index]);
            }

            RecordsActivity::log(
                LogChannel::Facility,
                'layout.group.reordered',
                null,
                [
                    'entity_type' => $type->value,
                    'ids' => $ids,
                ],
                $request->user(),
            );
        });

        return $this->show($type->value);
    }

    public function destroyGroup(Request $request, AttributeGroup $group): JsonResponse
    {
        if ($group->is_system) {
            throw ValidationException::withMessages([
                'group' => ['System cards cannot be deleted.'],
            ]);
        }

        if ($group->fields()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['Remove all fields from this card before deleting it.'],
            ]);
        }

        DB::transaction(function () use ($group, $request) {
            RecordsActivity::log(
                LogChannel::Facility,
                'layout.group.deleted',
                $group,
                [
                    'entity_type' => $group->entity_type->value,
                    'key' => $group->key,
                    'label' => $group->label,
                ],
                $request->user(),
            );

            $group->delete();
        });

        return $this->success(null, 'Card deleted successfully.');
    }

    public function storeField(Request $request, AttributeGroup $group): JsonResponse
    {
        $validated = $request->validate([
            'field_type' => ['required', Rule::enum(LayoutFieldType::class)],
            'native_field_key' => ['required_if:field_type,native', 'nullable', 'string'],
            'attribute_definition_id' => [
                'required_if:field_type,attribute',
                'nullable',
                'integer',
                'exists:attribute_definitions,id',
            ],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $fieldType = LayoutFieldType::from($validated['field_type']);
        $entityType = $group->entity_type;

        $nativeKey = null;
        $definitionId = null;

        if ($fieldType === LayoutFieldType::Native) {
            $nativeKey = (string) $validated['native_field_key'];
            NativeFields::assertExists($entityType, $nativeKey);

            if (LayoutField::query()
                ->where('entity_type', $entityType)
                ->where('native_field_key', $nativeKey)
                ->exists()) {
                throw ValidationException::withMessages([
                    'native_field_key' => ['This native field is already placed on a card.'],
                ]);
            }
        } else {
            $definitionId = (int) $validated['attribute_definition_id'];
            $definition = AttributeDefinition::query()->findOrFail($definitionId);

            if ($definition->entity_type !== $entityType) {
                throw ValidationException::withMessages([
                    'attribute_definition_id' => ['Attribute definition entity type mismatch.'],
                ]);
            }

            if ($definition->isArchived()) {
                throw ValidationException::withMessages([
                    'attribute_definition_id' => ['Archived attributes cannot be added to a card.'],
                ]);
            }

            if (LayoutField::query()
                ->where('entity_type', $entityType)
                ->where('attribute_definition_id', $definitionId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'attribute_definition_id' => ['This attribute is already placed on a card.'],
                ]);
            }
        }

        $currentMax = $group->fields()->max('display_order');
        $maxOrder = $currentMax === null ? -1 : (int) $currentMax;
        $position = array_key_exists('position', $validated) && $validated['position'] !== null
            ? (int) $validated['position']
            : $maxOrder + 1;

        try {
            $field = DB::transaction(function () use (
                $group,
                $entityType,
                $fieldType,
                $nativeKey,
                $definitionId,
                $position,
                $maxOrder,
                $request,
            ) {
                if ($position <= $maxOrder) {
                    LayoutField::query()
                        ->where('group_id', $group->id)
                        ->where('display_order', '>=', $position)
                        ->increment('display_order');
                }

                $field = LayoutField::query()->create([
                    'group_id' => $group->id,
                    'entity_type' => $entityType,
                    'display_order' => $position,
                    'field_type' => $fieldType,
                    'native_field_key' => $nativeKey,
                    'attribute_definition_id' => $definitionId,
                ]);

                RecordsActivity::log(
                    LogChannel::Facility,
                    'layout.field.added',
                    $field,
                    [
                        'entity_type' => $entityType->value,
                        'group_id' => $group->id,
                        'field_type' => $fieldType->value,
                        'native_field_key' => $nativeKey,
                        'attribute_definition_id' => $definitionId,
                    ],
                    $request->user(),
                );

                return $field->load('attributeDefinition.options');
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'field' => ['This field is already placed on a card for this entity.'],
            ]);
        }

        return $this->created(
            LayoutFieldResource::make($field)->resolve(),
            'Field added successfully.',
        );
    }

    public function updateField(Request $request, LayoutField $field): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => ['sometimes', 'required', 'integer', 'exists:attribute_groups,id'],
            'display_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $targetGroup = array_key_exists('group_id', $validated)
            ? AttributeGroup::query()->findOrFail($validated['group_id'])
            : $field->group;

        if ($targetGroup->entity_type !== $field->entity_type) {
            throw ValidationException::withMessages([
                'group_id' => ['Cannot move a field to a card on a different entity.'],
            ]);
        }

        $field = DB::transaction(function () use ($validated, $field, $targetGroup, $request) {
            $updates = [];

            if (array_key_exists('group_id', $validated)) {
                $updates['group_id'] = $targetGroup->id;
            }

            if (array_key_exists('display_order', $validated)) {
                $updates['display_order'] = $validated['display_order'];
            }

            if ($updates !== []) {
                $field->update($updates);
            }

            RecordsActivity::log(
                LogChannel::Facility,
                'layout.field.moved',
                $field,
                [
                    'entity_type' => $field->entity_type->value,
                    'group_id' => $field->group_id,
                    'display_order' => $field->display_order,
                    'field_type' => $field->field_type->value,
                    'native_field_key' => $field->native_field_key,
                    'attribute_definition_id' => $field->attribute_definition_id,
                ],
                $request->user(),
            );

            return $field->fresh()->load('attributeDefinition.options');
        });

        return $this->success(
            LayoutFieldResource::make($field)->resolve(),
            'Field updated successfully.',
        );
    }

    public function reorderFields(Request $request, AttributeGroup $group): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values($validated['ids']);

        $existingIds = $group->fields()->orderBy('display_order')->pluck('id')->all();

        if (count($ids) !== count($existingIds) || array_diff($existingIds, $ids) !== []) {
            throw ValidationException::withMessages([
                'ids' => ['ids must include every field in this card exactly once.'],
            ]);
        }

        DB::transaction(function () use ($ids, $group, $request) {
            foreach ($ids as $index => $id) {
                LayoutField::query()->whereKey($id)->update(['display_order' => $index]);
            }

            RecordsActivity::log(
                LogChannel::Facility,
                'layout.field.reordered',
                $group,
                [
                    'entity_type' => $group->entity_type->value,
                    'group_id' => $group->id,
                    'ids' => $ids,
                ],
                $request->user(),
            );
        });

        $group->load(['fields.attributeDefinition.options']);

        return $this->success(
            AttributeGroupResource::make($group)->resolve(),
            'Fields reordered successfully.',
        );
    }

    public function destroyField(Request $request, LayoutField $field): JsonResponse
    {
        DB::transaction(function () use ($field, $request) {
            RecordsActivity::log(
                LogChannel::Facility,
                'layout.field.removed',
                $field,
                [
                    'entity_type' => $field->entity_type->value,
                    'group_id' => $field->group_id,
                    'field_type' => $field->field_type->value,
                    'native_field_key' => $field->native_field_key,
                    'attribute_definition_id' => $field->attribute_definition_id,
                ],
                $request->user(),
            );

            $field->delete();
        });

        return $this->success(null, 'Field removed successfully.');
    }

    private function resolveEntityType(string $entityType): AttributeEntityType
    {
        return AttributeEntityType::tryFrom($entityType)
            ?? throw ValidationException::withMessages([
                'entityType' => ['Unsupported entity type.'],
            ]);
    }

    private function uniqueGroupKey(AttributeEntityType $type, string $label): string
    {
        $base = Str::slug($label, '_');
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($base)) ?: 'card';
        $base = preg_replace('/_+/', '_', $base) ?? $base;
        $base = trim($base, '_');

        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'card_'.$base;
            $base = ltrim($base, '_');
        }

        $candidate = $base;
        $suffix = 2;

        while (AttributeGroup::query()
            ->where('entity_type', $type)
            ->where('key', $candidate)
            ->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
