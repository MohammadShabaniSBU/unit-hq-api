<?php

namespace App\Http\Controllers;

use App\Models\Insurance;
use App\Models\InsuranceRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsuranceController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
        ]);

        $siteId = $validated['site_id'];

        $latestRateIds = InsuranceRate::query()
            ->selectRaw('MAX(id) as id')
            ->where('site_id', $siteId)
            ->groupBy('insurance_id')
            ->pluck('id');

        $ratesByInsurance = InsuranceRate::query()
            ->with('price')
            ->whereIn('id', $latestRateIds)
            ->get()
            ->keyBy('insurance_id');

        $options = Insurance::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Insurance $insurance) => [
                'value' => $insurance->id,
                'label' => $insurance->name,
                'rate'  => $ratesByInsurance->get($insurance->id)?->price?->amount,
            ]);

        return $this->success($options, 'Insurance options retrieved successfully.');
    }
}
