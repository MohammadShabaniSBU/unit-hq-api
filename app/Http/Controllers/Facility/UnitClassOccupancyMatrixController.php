<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Time\SiteClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class UnitClassOccupancyMatrixController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $sites = Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'timezone']);

        $unitClasses = UnitClass::query()
            ->orderBy('code')
            ->get(['id', 'code', 'label']);

        // Totals: one grouped query for enabled units per site × class.
        $totals = Unit::query()
            ->where('enabled', true)
            ->select('site_id', 'unit_class_id', DB::raw('COUNT(*) as total'))
            ->groupBy('site_id', 'unit_class_id')
            ->get()
            ->keyBy(fn ($row) => $row->site_id.'|'.$row->unit_class_id);

        // Occupied counts: one query per distinct timezone (D8 — today is per site).
        // Grouping by timezone keeps this bounded; it is not one query per site.
        $occupied = collect();

        /** @var Collection<string, Collection<int, Site>> $byTimezone */
        $byTimezone = $sites->groupBy(fn (Site $site): string => $site->timezone);

        foreach ($byTimezone as $group) {
            $on = SiteClock::today($group->first())->format('Y-m-d');
            $siteIds = $group->pluck('id')->all();

            $rows = UnitOccupancy::query()
                ->select('units.site_id', 'units.unit_class_id', DB::raw('COUNT(DISTINCT unit_occupancies.unit_id) as occupied'))
                ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                ->whereIn('units.site_id', $siteIds)
                ->where('units.enabled', true)
                ->where('unit_occupancies.started_on', '<=', $on)
                ->where(function ($q) use ($on): void {
                    $q->whereNull('unit_occupancies.ended_on')
                        ->orWhere('unit_occupancies.ended_on', '>', $on);
                })
                ->groupBy('units.site_id', 'units.unit_class_id')
                ->get();

            foreach ($rows as $row) {
                $occupied->put($row->site_id.'|'.$row->unit_class_id, (int) $row->occupied);
            }
        }

        $rows = $unitClasses->map(function (UnitClass $unitClass) use ($sites, $totals, $occupied) {
            $occupancy = [];

            foreach ($sites as $site) {
                $key = $site->id.'|'.$unitClass->id;
                $total = (int) ($totals->get($key)?->total ?? 0);

                if ($total === 0) {
                    $occupancy[(string) $site->id] = null;
                    continue;
                }

                $occupiedCount = (int) ($occupied->get($key) ?? 0);
                $percentage = round($occupiedCount / $total * 100, 1);

                $occupancy[(string) $site->id] = [
                    'occupied'   => $occupiedCount,
                    'total'      => $total,
                    'percentage' => $percentage,
                ];
            }

            return [
                'unit_class_id' => $unitClass->id,
                'code'          => $unitClass->code,
                'label'         => $unitClass->label,
                'occupancy'     => $occupancy,
            ];
        });

        return $this->success([
            'sites' => $sites->map(fn (Site $site) => [
                'id'   => $site->id,
                'name' => $site->name,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
        ], 'Unit class occupancy matrix retrieved successfully.');
    }
}
