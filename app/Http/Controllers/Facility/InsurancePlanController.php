<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsurancePlanResource;
use App\Models\Insurance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsurancePlanController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->paginated(
            Insurance::query()
                ->orderBy('name')
                ->paginate($this->perPage())
                ->through(fn (Insurance $insurance) => InsurancePlanResource::make($insurance)),
            'Insurance plans retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'coverage'    => ['required', 'numeric', 'min:0'],
            'currency'    => ['required', 'string', 'size:3'],
        ]);

        $insurance = Insurance::query()->create($validated);

        return $this->created(
            InsurancePlanResource::make($insurance),
            'Insurance plan created successfully.'
        );
    }

    public function update(Request $request, Insurance $insurance): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'coverage'    => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency'    => ['sometimes', 'required', 'string', 'size:3'],
        ]);

        $insurance->update($validated);

        return $this->success(
            InsurancePlanResource::make($insurance->fresh()),
            'Insurance plan updated successfully.'
        );
    }
}
