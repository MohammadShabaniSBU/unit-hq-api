<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitOccupancyResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class UnitOccupancyController extends Controller
{
    public function index(Unit $unit): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value, $unit);

        $occupancies = $unit->occupancies()
            ->with(['contract.contact'])
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->get();

        return $this->success(
            UnitOccupancyResource::collection($occupancies),
            'Unit occupancies retrieved successfully.',
        );
    }
}
