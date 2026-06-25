<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id'  => ['nullable', 'integer', 'exists:sites,id'],
            'for_map'  => ['nullable', 'boolean'],
        ]);

        $query = Unit::query()
            ->with(['site', 'unitClass'])
            ->withMapStatus()
            ->latest();

        if (! empty($validated['site_id'])) {
            $query->where('site_id', $validated['site_id']);
        }

        if ($request->boolean('for_map') && ! empty($validated['site_id'])) {
            $units = $query->get()->map(fn (Unit $unit) => UnitResource::make($unit));

            return $this->success($units, 'Units retrieved successfully.');
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Unit $unit) => UnitResource::make($unit)),
            'Units retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['required', 'integer', 'exists:unit_classes,id'],
            'unit_number'   => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'unit_number')->where('site_id', $request->integer('site_id')),
            ],
            'actual_width'  => ['nullable', 'numeric', 'min:0'],
            'actual_depth'  => ['nullable', 'numeric', 'min:0'],
            'actual_height' => ['nullable', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string'],
            'enabled'       => ['nullable', 'boolean'],
        ]);

        $unit = Unit::query()->create($validated);

        return $this->created(
            UnitResource::make($unit),
            'Unit created successfully.'
        );
    }

    public function show(Unit $unit): JsonResponse
    {
        return $this->success(
            UnitResource::make($unit),
            'Unit retrieved successfully.'
        );
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $siteId = $request->filled('site_id') ? $request->integer('site_id') : $unit->site_id;

        $validated = $request->validate([
            'site_id'       => ['sometimes', 'required', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['sometimes', 'required', 'integer', 'exists:unit_classes,id'],
            'unit_number'   => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'unit_number')
                    ->where('site_id', $siteId)
                    ->ignore($unit->id),
            ],
            'actual_width'  => ['nullable', 'numeric', 'min:0'],
            'actual_depth'  => ['nullable', 'numeric', 'min:0'],
            'actual_height' => ['nullable', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string'],
            'enabled'       => ['nullable', 'boolean'],
        ]);

        $unit->update($validated);

        return $this->success(
            UnitResource::make($unit->fresh()),
            'Unit updated successfully.'
        );
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $unit->delete();

        return $this->noContent('Unit deleted successfully.');
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['nullable', 'integer', 'exists:unit_classes,id'],
        ]);

        $query = Unit::query()
            ->with(['site', 'unitClass'])
            ->where('enabled', true)
            ->reservable()
            ->limit(100);

        if (! empty($validated['site_id'])) {
            $query->where('site_id', $validated['site_id']);
        }

        if (! empty($validated['unit_class_id'])) {
            $query->where('unit_class_id', $validated['unit_class_id']);
        }

        $options = $query->get()->map(fn (Unit $unit) => [
            'value' => $unit->id,
            'label' => $unit->unit_number
                . ($unit->site ? ' · ' . $unit->site->name : '')
                . ($unit->unitClass ? ' · ' . $unit->unitClass->label : ''),
        ]);

        return $this->success($options, 'Unit options retrieved successfully.');
    }
}
