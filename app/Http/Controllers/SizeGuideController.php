<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SizeGuideMetric;
use App\Http\Resources\SizeGuideResource;
use App\Models\SizeGuide;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SizeGuideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = SizeGuide::query()
            ->with(['site:id,name', 'unitClass:id,label'])
            ->orderBy('metric')
            ->orderBy('min_quantity');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success(
            SizeGuideResource::collection($query->get())->resolve(),
            'Size guides retrieved successfully.',
        );
    }

    public function show(SizeGuide $sizeGuide): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $sizeGuide);

        $sizeGuide->load(['site:id,name', 'unitClass:id,label']);

        return $this->success(
            SizeGuideResource::make($sizeGuide),
            'Size guide retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $validated = $this->validatedPayload($request, creating: true);

        $guide = SizeGuide::query()->create($validated);
        $guide->load(['site:id,name', 'unitClass:id,label']);

        return $this->created(
            SizeGuideResource::make($guide),
            'Size guide created successfully.',
        );
    }

    public function update(Request $request, SizeGuide $sizeGuide): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $sizeGuide);

        $validated = $this->validatedPayload($request, creating: false, guide: $sizeGuide);

        if ($validated !== []) {
            $sizeGuide->update($validated);
        }

        $sizeGuide->load(['site:id,name', 'unitClass:id,label']);

        return $this->success(
            SizeGuideResource::make($sizeGuide->fresh(['site:id,name', 'unitClass:id,label'])),
            'Size guide updated successfully.',
        );
    }

    public function archive(SizeGuide $sizeGuide): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $sizeGuide);

        if ($sizeGuide->isArchived()) {
            $sizeGuide->load(['site:id,name', 'unitClass:id,label']);

            return $this->success(
                SizeGuideResource::make($sizeGuide),
                'Size guide is already archived.',
            );
        }

        $sizeGuide->update(['archived_at' => now()]);
        $sizeGuide->load(['site:id,name', 'unitClass:id,label']);

        return $this->success(
            SizeGuideResource::make($sizeGuide->fresh(['site:id,name', 'unitClass:id,label'])),
            'Size guide archived successfully.',
        );
    }

    public function unarchive(SizeGuide $sizeGuide): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $sizeGuide);

        if (! $sizeGuide->isArchived()) {
            $sizeGuide->load(['site:id,name', 'unitClass:id,label']);

            return $this->success(
                SizeGuideResource::make($sizeGuide),
                'Size guide is already active.',
            );
        }

        $sizeGuide->update(['archived_at' => null]);
        $sizeGuide->load(['site:id,name', 'unitClass:id,label']);

        return $this->success(
            SizeGuideResource::make($sizeGuide->fresh(['site:id,name', 'unitClass:id,label'])),
            'Size guide restored successfully.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating, ?SizeGuide $guide = null): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'metric' => [$sometimes, Rule::enum(SizeGuideMetric::class)],
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['sometimes', 'nullable', 'integer', 'exists:unit_classes,id'],
            'min_size' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_size' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $classId = array_key_exists('unit_class_id', $validated)
            ? $validated['unit_class_id']
            : $guide?->unit_class_id;
        $minSize = array_key_exists('min_size', $validated)
            ? $validated['min_size']
            : $guide?->min_size;
        $maxSize = array_key_exists('max_size', $validated)
            ? $validated['max_size']
            : $guide?->max_size;

        if ($classId === null && $minSize === null && $maxSize === null) {
            throw ValidationException::withMessages([
                'min_size' => ['A size band requires min_size or max_size when no unit class is set.'],
            ]);
        }

        if ($classId !== null) {
            $validated['min_size'] = null;
            $validated['max_size'] = null;
        } else {
            if (array_key_exists('min_size', $validated) && $validated['min_size'] !== null) {
                $validated['min_size'] = number_format((float) $validated['min_size'], 2, '.', '');
            }
            if (array_key_exists('max_size', $validated) && $validated['max_size'] !== null) {
                $validated['max_size'] = number_format((float) $validated['max_size'], 2, '.', '');
            }
        }

        $minQty = array_key_exists('min_quantity', $validated)
            ? $validated['min_quantity']
            : $guide?->min_quantity;
        $maxQty = array_key_exists('max_quantity', $validated)
            ? $validated['max_quantity']
            : $guide?->max_quantity;
        if ($minQty !== null && $maxQty !== null && (int) $minQty > (int) $maxQty) {
            throw ValidationException::withMessages([
                'max_quantity' => ['max_quantity must be greater than or equal to min_quantity.'],
            ]);
        }

        if ($minSize !== null && $maxSize !== null && $classId === null
            && (float) $minSize > (float) $maxSize) {
            throw ValidationException::withMessages([
                'max_size' => ['max_size must be greater than or equal to min_size.'],
            ]);
        }

        return $validated;
    }
}
