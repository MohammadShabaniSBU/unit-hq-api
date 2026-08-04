<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class InsurancePriceMatrixController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $sites = Site::query()
            ->visibleTo($employee, Permission::CatalogueManage)
            ->orderBy('name')
            ->get(['id', 'name']);

        // InsuranceRate has no registered SitePath — filter manually by the
        // same granted site ids (null = company-wide, no filter added).
        $scopedSiteIds = $employee->siteIdsFor(Permission::CatalogueManage);

        $rates = InsuranceRate::query()
            ->when($scopedSiteIds !== null, fn ($q) => $q->whereIn('site_id', $scopedSiteIds))
            ->with('price')
            ->get();

        $rateMap = [];

        foreach ($rates as $rate) {
            if ($rate->price === null) {
                continue;
            }

            $rateMap[$rate->insurance_id][$rate->site_id] = [
                'amount'   => $rate->price->amount,
                'currency' => $rate->price->currency,
            ];
        }

        $insurances = Insurance::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = $insurances->map(function (Insurance $insurance) use ($sites, $rateMap) {
            $rates = [];

            foreach ($sites as $site) {
                $rates[(string) $site->id] = $rateMap[$insurance->id][$site->id] ?? null;
            }

            return [
                'insurance_id' => $insurance->id,
                'name'         => $insurance->name,
                'rates'        => $rates,
            ];
        });

        return $this->success([
            'sites' => $sites->map(fn (Site $site) => [
                'id'   => $site->id,
                'name' => $site->name,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
        ], 'Insurance rate matrix retrieved successfully.');
    }
}
