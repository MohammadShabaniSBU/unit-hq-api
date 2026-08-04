<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitClassRateResource;
use App\Models\UnitClassRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class UnitClassRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'site_id'       => ['nullable', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['nullable', 'integer', 'exists:unit_classes,id'],
        ]);

        $query = UnitClassRate::query()->visibleTo($employee, Permission::CatalogueManage)->with('price');

        if (isset($validated['site_id'])) {
            $query->where('site_id', $validated['site_id']);
        }

        if (isset($validated['unit_class_id'])) {
            $query->where('unit_class_id', $validated['unit_class_id']);
        }

        return $this->paginated(
            $query->latest()->paginate($this->perPage())->through(fn (UnitClassRate $rate) => UnitClassRateResource::make($rate)),
            'Unit class rates retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $validated = $request->validate([
            'unit_class_id' => ['required', 'integer', 'exists:unit_classes,id'],
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
        ]);

        $unitClassRate = UnitClassRate::query()->firstOrCreate($validated);

        return $this->created(
            UnitClassRateResource::make($unitClassRate->load('price')),
            'Unit class rate created successfully.'
        );
    }
}
