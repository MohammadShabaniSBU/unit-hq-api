<?php

namespace App\Http\Controllers\Facility;

use App\Enums\LogChannel;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitClassPriceController extends Controller
{
    public function index(UnitClass $unitClass): JsonResponse
    {
        $latestRateIds = UnitClassRate::query()
            ->selectRaw('MAX(id) as id')
            ->where('unit_class_id', $unitClass->id)
            ->groupBy('site_id')
            ->pluck('id');

        $ratesBySite = UnitClassRate::query()
            ->with('price')
            ->whereIn('id', $latestRateIds)
            ->get()
            ->keyBy('site_id');

        $sites = Site::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site) => $this->formatSitePrice($site, $ratesBySite->get($site->id)));

        return $this->success(
            $sites->values()->all(),
            'Unit class prices retrieved successfully.'
        );
    }

    public function store(Request $request, UnitClass $unitClass): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'amount'  => ['required', 'numeric', 'min:0'],
        ]);

        $billing = Setting::billing();

        if ($billing->defaultCurrency === '' || $billing->defaultBillingPeriod === '') {
            throw ValidationException::withMessages([
                'amount' => ['Billing settings must be configured before setting prices.'],
            ]);
        }

        $createdBy = $request->user()?->id ?? Employee::query()->value('id');

        if ($createdBy === null) {
            throw ValidationException::withMessages([
                'amount' => ['No employee record found to attribute this price change.'],
            ]);
        }

        $today = Carbon::today()->toDateString();

        $sitePrice = DB::transaction(function () use ($unitClass, $validated, $billing, $createdBy, $today) {
            $existingRate = UnitClassRate::query()
                ->with('price')
                ->where('unit_class_id', $unitClass->id)
                ->where('site_id', $validated['site_id'])
                ->latest('id')
                ->first();

            if ($existingRate?->price) {
                $existingRate->price->update(['effective_to' => $today]);
            }

            $price = Price::query()->create([
                'amount'         => $validated['amount'],
                'currency'       => $billing->defaultCurrency,
                'billing_period' => $billing->defaultBillingPeriod,
                'effective_from' => $today,
                'effective_to'   => null,
                'created_by'     => $createdBy,
            ]);

            UnitClassRate::query()->create([
                'unit_class_id' => $unitClass->id,
                'site_id'       => $validated['site_id'],
                'price_id'      => $price->id,
            ]);

            RecordsActivity::log(LogChannel::Facility, 'rate.changed', $unitClass, [
                'unit_class_id' => $unitClass->id,
                'site_id' => $validated['site_id'],
                'old_price_id' => $existingRate?->price_id,
                'new_price_id' => $price->id,
                'amount' => (string) $price->amount,
                'currency' => $price->currency,
            ]);

            $site = Site::query()->findOrFail($validated['site_id']);

            return $this->formatSitePrice($site, null, $price);
        });

        return $this->created(
            $sitePrice,
            'Unit class price created successfully.'
        );
    }

    /** @return array<string, mixed> */
    private function formatSitePrice(Site $site, ?UnitClassRate $rate = null, ?Price $price = null): array
    {
        $price ??= $rate?->price;

        return [
            'unit_class_rate_id' => $rate?->id,
            'site_id'            => $site->id,
            'site_name'          => $site->name,
            'price_id'           => $price?->id,
            'amount'             => $price?->amount,
            'currency'           => $price?->currency,
            'billing_period'     => $price?->billing_period,
        ];
    }
}
