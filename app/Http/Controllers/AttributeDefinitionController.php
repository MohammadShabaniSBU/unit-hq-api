<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Http\Resources\AttributeDefinitionResource;
use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use App\Support\Auth\Permission;
use App\Support\Filtering\AttributeFieldResolver;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttributeDefinitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $validated = $request->validate([
            'entity_type' => ['nullable', Rule::enum(AttributeEntityType::class)],
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = AttributeDefinition::query()
            ->with('options')
            ->orderBy('entity_type')
            ->orderBy('display_order')
            ->orderBy('label');

        if (! empty($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success(
            AttributeDefinitionResource::collection($query->get())->resolve(),
            'Attribute definitions retrieved successfully.'
        );
    }

    /**
     * Entity-scoped definition list for create/edit forms (not SettingsManage).
     * Auth uses the entity type's manage permission.
     */
    public function forEntity(Request $request, string $entityType): JsonResponse
    {
        $type = AttributeEntityType::tryFrom($entityType)
            ?? throw ValidationException::withMessages([
                'entityType' => ['Unsupported entity type.'],
            ]);

        Gate::authorize($type->managePermission()->value);

        $request->validate([
            'required' => ['nullable', 'boolean'],
        ]);

        $query = AttributeDefinition::query()
            ->with('options')
            ->active()
            ->where('entity_type', $type)
            ->orderBy('display_order')
            ->orderBy('label');

        if ($request->boolean('required')) {
            $query->where('is_required', true);
        }

        return $this->success(
            AttributeDefinitionResource::collection($query->get())->resolve(),
            'Attribute definitions retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $validated = $this->validateDefinition($request);

        $definition = DB::transaction(function () use ($validated, $request) {
            $definition = AttributeDefinition::query()->create([
                'entity_type' => $validated['entity_type'],
                'key' => $validated['key'],
                'label' => $validated['label'],
                'type' => $validated['type'],
                'group_name' => $validated['group_name'] ?? null,
                'display_order' => $validated['display_order'] ?? 0,
                'is_required' => $validated['is_required'] ?? false,
            ]);

            $this->syncOptions($definition, $validated['options'] ?? []);

            RecordsActivity::log(
                AttributeEntityType::from($validated['entity_type'])->activityChannel(),
                'attribute.definition.created',
                $definition,
                [
                    'entity_type' => $definition->entity_type->value,
                    'key' => $definition->key,
                    'type' => $definition->type->value,
                ],
                $request->user(),
            );

            return $definition->load('options');
        });

        AttributeFieldResolver::forget($definition->entity_type);

        return $this->created(
            AttributeDefinitionResource::make($definition),
            'Attribute definition created successfully.'
        );
    }

    public function show(AttributeDefinition $attributeDefinition): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value, $attributeDefinition);

        $attributeDefinition->load('options');

        return $this->success(
            AttributeDefinitionResource::make($attributeDefinition),
            'Attribute definition retrieved successfully.'
        );
    }

    public function update(Request $request, AttributeDefinition $attributeDefinition): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value, $attributeDefinition);

        $validated = $this->validateDefinition($request, isUpdate: true, definition: $attributeDefinition);

        $definition = DB::transaction(function () use ($validated, $attributeDefinition, $request) {
            $attributeDefinition->update([
                'label' => $validated['label'] ?? $attributeDefinition->label,
                'group_name' => array_key_exists('group_name', $validated)
                    ? $validated['group_name']
                    : $attributeDefinition->group_name,
                'display_order' => $validated['display_order'] ?? $attributeDefinition->display_order,
                'is_required' => $validated['is_required'] ?? $attributeDefinition->is_required,
            ]);

            if (array_key_exists('options', $validated)) {
                $this->syncOptions($attributeDefinition, $validated['options'] ?? []);
            }

            RecordsActivity::log(
                $attributeDefinition->entity_type->activityChannel(),
                'attribute.definition.updated',
                $attributeDefinition,
                [
                    'entity_type' => $attributeDefinition->entity_type->value,
                    'key' => $attributeDefinition->key,
                    'type' => $attributeDefinition->type->value,
                ],
                $request->user(),
            );

            return $attributeDefinition->fresh()->load('options');
        });

        AttributeFieldResolver::forget($definition->entity_type);

        return $this->success(
            AttributeDefinitionResource::make($definition),
            'Attribute definition updated successfully.'
        );
    }

    public function archive(Request $request, AttributeDefinition $attributeDefinition): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value, $attributeDefinition);

        if ($attributeDefinition->isArchived()) {
            return $this->success(
                AttributeDefinitionResource::make($attributeDefinition->load('options')),
                'Attribute definition is already archived.'
            );
        }

        $definition = DB::transaction(function () use ($attributeDefinition, $request) {
            $attributeDefinition->update(['archived_at' => now()]);

            RecordsActivity::log(
                $attributeDefinition->entity_type->activityChannel(),
                'attribute.definition.archived',
                $attributeDefinition,
                [
                    'entity_type' => $attributeDefinition->entity_type->value,
                    'key' => $attributeDefinition->key,
                    'type' => $attributeDefinition->type->value,
                ],
                $request->user(),
            );

            return $attributeDefinition->fresh()->load('options');
        });

        AttributeFieldResolver::forget($definition->entity_type);

        return $this->success(
            AttributeDefinitionResource::make($definition),
            'Attribute definition archived successfully.'
        );
    }

    public function unarchive(Request $request, AttributeDefinition $attributeDefinition): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value, $attributeDefinition);

        if (! $attributeDefinition->isArchived()) {
            return $this->success(
                AttributeDefinitionResource::make($attributeDefinition->load('options')),
                'Attribute definition is already active.'
            );
        }

        $definition = DB::transaction(function () use ($attributeDefinition, $request) {
            $attributeDefinition->update(['archived_at' => null]);

            RecordsActivity::log(
                $attributeDefinition->entity_type->activityChannel(),
                'attribute.definition.unarchived',
                $attributeDefinition,
                [
                    'entity_type' => $attributeDefinition->entity_type->value,
                    'key' => $attributeDefinition->key,
                    'type' => $attributeDefinition->type->value,
                ],
                $request->user(),
            );

            return $attributeDefinition->fresh()->load('options');
        });

        AttributeFieldResolver::forget($definition->entity_type);

        return $this->success(
            AttributeDefinitionResource::make($definition),
            'Attribute definition restored successfully.'
        );
    }

    /** @return array<string, mixed> */
    private function validateDefinition(
        Request $request,
        bool $isUpdate = false,
        ?AttributeDefinition $definition = null,
    ): array {
        $sometimes = $isUpdate ? 'sometimes' : 'required';
        $type = $request->input('type', $definition?->type?->value);

        $rules = [
            'label' => [$sometimes, 'string', 'max:255'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'integer', 'exists:attribute_options,id'],
            'options.*.label' => ['required_with:options', 'string', 'max:255'],
            'options.*.display_order' => ['nullable', 'integer', 'min:0'],
        ];

        if (! $isUpdate) {
            $rules['entity_type'] = ['required', Rule::enum(AttributeEntityType::class)];
            $rules['key'] = [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('attribute_definitions', 'key')->where(
                    fn ($query) => $query->where('entity_type', $request->input('entity_type')),
                ),
            ];
            $rules['type'] = ['required', Rule::enum(AttributeType::class)];
        }

        $validated = $request->validate($rules);

        $resolvedType = AttributeType::tryFrom((string) $type);

        if ($resolvedType === null && ! $isUpdate) {
            throw ValidationException::withMessages([
                'type' => ['The selected type is invalid.'],
            ]);
        }

        $requiresOptions = $resolvedType?->requiresOptions() ?? $definition?->type->requiresOptions() ?? false;

        if ($requiresOptions) {
            if (! $isUpdate || array_key_exists('options', $validated)) {
                $options = $validated['options'] ?? [];
                if ($options === []) {
                    throw ValidationException::withMessages([
                        'options' => ['At least one option is required for select and multiselect attributes.'],
                    ]);
                }
            }
        } elseif (array_key_exists('options', $validated) && ($validated['options'] ?? []) !== []) {
            throw ValidationException::withMessages([
                'options' => ['Options are only allowed for select and multiselect attributes.'],
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<int, array{id?: int|null, label: string, display_order?: int|null}>  $options
     */
    private function syncOptions(AttributeDefinition $definition, array $options): void
    {
        if (! $definition->type->requiresOptions()) {
            $definition->options()->delete();

            return;
        }

        $keptIds = [];

        foreach (array_values($options) as $index => $option) {
            $displayOrder = $option['display_order'] ?? $index;
            $optionId = $option['id'] ?? null;

            if ($optionId !== null) {
                $existing = AttributeOption::query()
                    ->whereKey($optionId)
                    ->where('definition_id', $definition->id)
                    ->first();

                if ($existing === null) {
                    throw ValidationException::withMessages([
                        'options' => ["Option id {$optionId} does not belong to this definition."],
                    ]);
                }

                $existing->update([
                    'label' => $option['label'],
                    'display_order' => $displayOrder,
                ]);
                $keptIds[] = $existing->id;

                continue;
            }

            $created = $definition->options()->create([
                'label' => $option['label'],
                'display_order' => $displayOrder,
            ]);
            $keptIds[] = $created->id;
        }

        $definition->options()->whereNotIn('id', $keptIds)->delete();
    }
}
