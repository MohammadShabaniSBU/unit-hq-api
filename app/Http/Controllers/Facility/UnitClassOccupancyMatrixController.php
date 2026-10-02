<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Auth\Permission;
use App\Support\Occupancy\UnitClassOccupancyCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UnitClassOccupancyMatrixController extends Controller
{
    use AppliesPortalSiteFilter;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $sitesQuery = Site::query()
            ->visibleTo($employee, Permission::CatalogueManage)
            ->orderBy('name');

        $this->applyPortalSiteFilter($sitesQuery, $request, Site::class, Permission::CatalogueManage);

        $sites = $sitesQuery->get(['id', 'name', 'timezone']);

        $unitClasses = UnitClass::query()
            ->orderBy('code')
            ->get(['id', 'code', 'label']);

        $counts = UnitClassOccupancyCounts::forSites($sites);

        $siteIds = $sites->pluck('id')->all();
        $offered = UnitClassRate::query()
            ->when(
                $siteIds === [],
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereIn('site_id', $siteIds),
            )
            ->get(['site_id', 'unit_class_id'])
            ->mapWithKeys(fn (UnitClassRate $rate): array => [
                $rate->site_id.'|'.$rate->unit_class_id => true,
            ]);

        $rows = $unitClasses->map(function (UnitClass $unitClass) use ($sites, $counts, $offered) {
            $occupancy = [];

            foreach ($sites as $site) {
                $key = $site->id.'|'.$unitClass->id;
                $count = $counts->get($key);

                $occupancy[(string) $site->id] = [
                    'site_id' => $site->id,
                    'unit_class_id' => $unitClass->id,
                    'offered' => $offered->has($key),
                    'total' => $count['total'] ?? 0,
                    'rentable' => $count['rentable'] ?? 0,
                    'occupied' => $count['occupied'] ?? 0,
                    'held_blocking' => $count['held_blocking'] ?? 0,
                    'free' => $count['free'] ?? 0,
                ];
            }

            return [
                'unit_class_id' => $unitClass->id,
                'code' => $unitClass->code,
                'label' => $unitClass->label,
                'occupancy' => $occupancy,
            ];
        });

        return $this->success([
            'sites' => $sites->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
        ], 'Unit class occupancy matrix retrieved successfully.');
    }
}
