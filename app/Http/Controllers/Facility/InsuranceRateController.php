<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InsuranceRateController extends Controller
{
    public function store(Request $request, Insurance $insurance): JsonResponse
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

        $siteRate = DB::transaction(function () use ($insurance, $validated, $billing, $createdBy, $today) {
            $existingRate = InsuranceRate::query()
                ->with('price')
                ->where('insurance_id', $insurance->id)
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

            InsuranceRate::query()->create([
                'insurance_id' => $insurance->id,
                'site_id'      => $validated['site_id'],
                'price_id'     => $price->id,
            ]);

            $site = Site::query()->findOrFail($validated['site_id']);

            return $this->formatSiteRate($site, null, $price);
        });

        return $this->created(
            $siteRate,
            'Insurance rate created successfully.'
        );
    }

    /** @return array<string, mixed> */
    private function formatSiteRate(Site $site, ?InsuranceRate $rate = null, ?Price $price = null): array
    {
        $price ??= $rate?->price;

        return [
            'insurance_rate_id' => $rate?->id,
            'site_id'           => $site->id,
            'site_name'         => $site->name,
            'price_id'          => $price?->id,
            'amount'            => $price?->amount,
            'currency'          => $price?->currency,
            'billing_period'    => $price?->billing_period,
        ];
    }
}
