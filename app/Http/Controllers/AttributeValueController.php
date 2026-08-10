<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Http\Resources\AttributeValueResource;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Unit;
use App\Support\Attributes\AttributeValueUpserter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttributeValueController extends Controller
{
    public function index(string $entityType, int $entityId): JsonResponse
    {
        $type = $this->resolveEntityType($entityType);
        $entity = $this->resolveEntity($type, $entityId);

        Gate::authorize($type->viewPermission()->value, $entity);

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

        Gate::authorize($type->managePermission()->value, $entity);

        $definition = AttributeDefinition::query()
            ->with('options')
            ->findOrFail((int) $validated['definition_id']);

        if ($definition->entity_type !== $type) {
            throw ValidationException::withMessages([
                'definition_id' => ['Attribute definition entity type mismatch.'],
            ]);
        }

        $result = AttributeValueUpserter::upsert(
            $definition,
            $entity,
            $type,
            $validated['value'],
            $request->user(),
        );

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
}
