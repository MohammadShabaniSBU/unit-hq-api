<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\SupportedCurrencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InsuranceRateController extends Controller
{
    public function store(Request $request, Insurance $insurance): JsonResponse
    {
        if ($request->filled('currency')) {
            $request->merge([
                'currency' => SupportedCurrencies::normalize((string) $request->input('currency')),
            ]);
        }

        $validated = $request->validate([
            'site_id'                 => ['required', 'integer', 'exists:sites,id'],
            'amount'                  => ['required', 'numeric', 'min:0'],
            'currency'                => SupportedCurrencies::rules(required: false),
            'allow_currency_mismatch' => ['sometimes', 'boolean'],
        ]);

        $billing = Setting::billing();
        $billingPeriod = match ($billing->defaultBillingInterval) {
            'week' => 'weekly',
            'day' => 'daily',
            default => 'monthly',
        };

        $site = Site::query()->findOrFail($validated['site_id']);
        $currency = SupportedCurrencies::normalize(
            (string) ($validated['currency']
                ?? $site->currency
                ?? $billing->defaultCurrency
                ?: 'EUR')
        );

        if (! SupportedCurrencies::isAllowed($currency)) {
            throw ValidationException::withMessages([
                'currency' => ['The selected currency is invalid.'],
            ]);
        }

        CurrencyGuard::assertRateJunction(
            $site->currency,
            $currency,
            (bool) ($validated['allow_currency_mismatch'] ?? false),
        );

        $createdBy = $request->user()?->id ?? Employee::query()->value('id');

        if ($createdBy === null) {
            throw ValidationException::withMessages([
                'amount' => ['No employee record found to attribute this price change.'],
            ]);
        }

        $today = Carbon::today()->toDateString();

        $siteRate = DB::transaction(function () use ($insurance, $validated, $billingPeriod, $createdBy, $today, $site, $currency) {
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
                'currency'       => $currency,
                'billing_period' => $billingPeriod,
                'effective_from' => $today,
                'effective_to'   => null,
                'created_by'     => $createdBy,
            ]);

            InsuranceRate::query()->create([
                'insurance_id' => $insurance->id,
                'site_id'      => $validated['site_id'],
                'price_id'     => $price->id,
            ]);

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
