<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitClassResource;
use App\Models\Site;
use App\Models\UnitClass;
use App\Support\Time\SiteClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitClassController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->paginated(
            UnitClass::query()->latest()->paginate($this->perPage())->through(fn (UnitClass $unitClass) => UnitClassResource::make($unitClass)),
            'Unit classes retrieved successfully.'
        );
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $query = UnitClass::query()->orderBy('label');

        if (! empty($validated['site_id'])) {
            $site = Site::query()->findOrFail($validated['site_id']);
            $on = SiteClock::today($site);

            $query->whereHas('units', function ($unitsQuery) use ($validated, $on): void {
                $unitsQuery
                    ->where('site_id', $validated['site_id'])
                    ->where('enabled', true)
                    ->availableOn($on);
            });
        }

        $options = $query->get(['id', 'label'])
            ->map(fn (UnitClass $unitClass) => ['value' => $unitClass->id, 'label' => $unitClass->label]);

        return $this->success($options, 'Unit class options retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'          => ['required', 'string', 'max:255', 'unique:unit_classes,code'],
            'label'         => ['required', 'string', 'max:255'],
            'size'          => ['nullable', 'numeric', 'min:0'],
            'tax_rate_code' => ['nullable', 'string', 'max:100'],
        ]);

        $unitClass = UnitClass::query()->create($validated);

        return $this->created(
            UnitClassResource::make($unitClass),
            'Unit class created successfully.'
        );
    }

    public function show(UnitClass $unitClass): JsonResponse
    {
        return $this->success(
            UnitClassResource::make($unitClass),
            'Unit class retrieved successfully.'
        );
    }

    public function update(Request $request, UnitClass $unitClass): JsonResponse
    {
        $validated = $request->validate([
            'code'          => ['sometimes', 'required', 'string', 'max:255', Rule::unique('unit_classes', 'code')->ignore($unitClass->id)],
            'label'         => ['sometimes', 'required', 'string', 'max:255'],
            'size'          => ['nullable', 'numeric', 'min:0'],
            'tax_rate_code' => ['nullable', 'string', 'max:100'],
        ]);

        $unitClass->update($validated);

        return $this->success(
            UnitClassResource::make($unitClass->fresh()),
            'Unit class updated successfully.'
        );
    }

    public function destroy(UnitClass $unitClass): JsonResponse
    {
        $unitClass->delete();

        return $this->noContent('Unit class deleted successfully.');
    }
}
