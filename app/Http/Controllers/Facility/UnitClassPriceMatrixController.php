<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use Illuminate\Http\JsonResponse;

class UnitClassPriceMatrixController extends Controller
{
    public function index(): JsonResponse
    {
        $sites = Site::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $rates = UnitClassRate::query()
            ->with('price')
            ->get();

        $rateMap = [];

        foreach ($rates as $rate) {
            if ($rate->price === null) {
                continue;
            }

            $rateMap[$rate->unit_class_id][$rate->site_id] = [
                'amount'   => $rate->price->amount,
                'currency' => $rate->price->currency,
            ];
        }

        $unitClasses = UnitClass::query()
            ->orderBy('code')
            ->get(['id', 'code', 'label']);

        $rows = $unitClasses->map(function (UnitClass $unitClass) use ($sites, $rateMap) {
            $prices = [];

            foreach ($sites as $site) {
                $prices[(string) $site->id] = $rateMap[$unitClass->id][$site->id] ?? null;
            }

            return [
                'unit_class_id' => $unitClass->id,
                'code'          => $unitClass->code,
                'label'         => $unitClass->label,
                'prices'        => $prices,
            ];
        });

        return $this->success([
            'sites' => $sites->map(fn (Site $site) => [
                'id'   => $site->id,
                'name' => $site->name,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
        ], 'Unit class price matrix retrieved successfully.');
    }
}
