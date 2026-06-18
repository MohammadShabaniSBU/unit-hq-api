<?php

namespace App\Http\Controllers;

use App\Enums\DealStatus;
use App\Enums\StayPeriod;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Deal::query()->with(['desiredUnitClass', 'contact'])->latest();

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->integer('contact_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Deal $deal) => DealResource::make($deal)),
            'Deals retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id'            => ['required', 'integer', 'exists:contacts,id'],
            'status'                => ['nullable', Rule::enum(DealStatus::class)],
            'expected_value'        => ['nullable', 'numeric', 'min:0'],
            'expected_move_in'      => ['nullable', 'date'],
            'expected_stay_length'  => ['nullable', 'integer', 'min:1'],
            'expected_stay_period'  => ['nullable', Rule::enum(StayPeriod::class)],
            'storage_reason'        => ['nullable', 'string'],
            'desired_size'          => ['nullable', 'numeric', 'min:0'],
            'desired_unit_class_id' => ['nullable', 'integer', 'exists:unit_classes,id'],
            'intent_notes'          => ['nullable', 'string'],
        ]);

        $deal = Deal::query()->create($validated);

        return $this->created(
            DealResource::make($deal->load('desiredUnitClass')),
            'Deal created successfully.'
        );
    }

    public function show(Deal $deal): JsonResponse
    {
        return $this->success(
            DealResource::make($deal->load('desiredUnitClass')),
            'Deal retrieved successfully.'
        );
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        $validated = $request->validate([
            'contact_id'            => ['sometimes', 'required', 'integer', 'exists:contacts,id'],
            'status'                => ['sometimes', 'nullable', Rule::enum(DealStatus::class)],
            'expected_value'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expected_move_in'      => ['sometimes', 'nullable', 'date'],
            'expected_stay_length'  => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expected_stay_period'  => ['sometimes', 'nullable', Rule::enum(StayPeriod::class)],
            'storage_reason'        => ['sometimes', 'nullable', 'string'],
            'desired_size'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'desired_unit_class_id' => ['sometimes', 'nullable', 'integer', 'exists:unit_classes,id'],
            'intent_notes'          => ['sometimes', 'nullable', 'string'],
        ]);

        $deal->update($validated);

        return $this->success(
            DealResource::make($deal->fresh()->load('desiredUnitClass')),
            'Deal updated successfully.'
        );
    }

    public function destroy(Deal $deal): JsonResponse
    {
        $deal->delete();

        return $this->noContent('Deal deleted successfully.');
    }
}
