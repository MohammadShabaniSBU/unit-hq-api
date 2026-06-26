<?php

namespace App\Http\Controllers\Facility;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class UnitClassOccupancyMatrixController extends Controller
{
    public function index(): JsonResponse
    {
        $sites = Site::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $units = Unit::query()
            ->where('enabled', true)
            ->withExists([
                'contractItems as has_active_contract' => function ($query): void {
                    $query
                        ->where('item_type', (new Unit)->getMorphClass())
                        ->whereHas('contract', function ($q): void {
                            $q->where('status', ContractStatus::Active->value);
                        });
                },
            ])
            ->get(['id', 'site_id', 'unit_class_id']);

        /** @var Collection<int, Collection<int, \Illuminate\Support\Collection>> $grouped */
        $grouped = $units->groupBy('unit_class_id');

        $unitClasses = UnitClass::query()
            ->orderBy('code')
            ->get(['id', 'code', 'label']);

        $rows = $unitClasses->map(function (UnitClass $unitClass) use ($sites, $grouped) {
            $clasUnits = $grouped->get($unitClass->id, collect());
            $bySite    = $clasUnits->groupBy('site_id');

            $occupancy = [];

            foreach ($sites as $site) {
                $siteUnits = $bySite->get($site->id, collect());
                $total     = $siteUnits->count();

                if ($total === 0) {
                    $occupancy[(string) $site->id] = null;
                    continue;
                }

                $occupied   = $siteUnits->filter(fn ($u) => (bool) $u->has_active_contract)->count();
                $percentage = round($occupied / $total * 100, 1);

                $occupancy[(string) $site->id] = [
                    'occupied'   => $occupied,
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
