<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfferOptionResource;
use App\Models\OfferOption;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferOptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offer_id'           => ['required', 'integer', 'exists:offers,id'],
            'unit_class_rate_id' => ['required', 'integer', 'exists:unit_class_rates,id'],
            'discount_id'        => ['nullable', 'integer', 'exists:discounts,id'],
            'label'          => ['required', 'string'],
            'description'    => ['nullable', 'string'],
            'display_order'  => ['required', 'integer', 'min:0'],
        ]);

        $validated['unit_id'] = Unit::resolveUnitIdForRate($validated['unit_class_rate_id']);

        $offerOption = OfferOption::query()->create($validated);

        $offerOption->load(OfferOption::unitClassRateEagerLoads());

        return $this->created(
            OfferOptionResource::make($offerOption),
            'Offer option created successfully.'
        );
    }

    public function update(Request $request, OfferOption $offerOption): JsonResponse
    {
        $validated = $request->validate([
            'unit_class_rate_id' => ['sometimes', 'required', 'integer', 'exists:unit_class_rates,id'],
            'discount_id'        => ['sometimes', 'nullable', 'integer', 'exists:discounts,id'],
            'label'          => ['sometimes', 'required', 'string'],
            'description'    => ['sometimes', 'nullable', 'string'],
            'display_order'  => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        if (array_key_exists('unit_class_rate_id', $validated)) {
            $validated['unit_id'] = Unit::resolveUnitIdForRate($validated['unit_class_rate_id']);
        }

        $offerOption->update($validated);

        $offerOption = $offerOption->fresh()->load(OfferOption::unitClassRateEagerLoads());

        return $this->success(
            OfferOptionResource::make($offerOption),
            'Offer option updated successfully.'
        );
    }

    public function destroy(OfferOption $offerOption): JsonResponse
    {
        $offerOption->delete();

        return $this->noContent('Offer option deleted successfully.');
    }
}
