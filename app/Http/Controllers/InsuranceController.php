<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Insurance;
use App\Models\InsuranceRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class InsuranceController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
        ]);

        $siteId = $validated['site_id'];

        $ratesByInsurance = InsuranceRate::query()
            ->with('price')
            ->where('site_id', $siteId)
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
