<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Site;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Site $site): JsonResponse
    {
        return $this->paginated(
            $site->units()->latest()->paginate($this->perPage())->through(fn (Unit $unit) => UnitResource::make($unit)),
            'Units retrieved successfully.'
        );
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'unit_class_id' => ['required', 'integer', 'exists:unit_classes,id'],
            'unit_number'   => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'unit_number')->where('site_id', $site->id),
            ],
            'actual_width'  => ['nullable', 'numeric', 'min:0'],
            'actual_depth'  => ['nullable', 'numeric', 'min:0'],
            'actual_height' => ['nullable', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string'],
            'enabled'       => ['nullable', 'boolean'],
        ]);

        $unit = $site->units()->create($validated);

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
        $validated = $request->validate([
            'unit_class_id' => ['sometimes', 'required', 'integer', 'exists:unit_classes,id'],
            'unit_number'   => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'unit_number')
                    ->where('site_id', $unit->site_id)
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
}
