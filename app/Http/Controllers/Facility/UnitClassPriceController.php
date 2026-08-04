<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\LogChannel;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\SupportedCurrencies;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class UnitClassPriceController extends Controller
{
    public function index(UnitClass $unitClass): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $unitClass);

        $ratesBySite = UnitClassRate::query()
            ->with('price')
            ->where('unit_class_id', $unitClass->id)
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
        Gate::authorize(Permission::CatalogueManage->value, $unitClass);

        if ($request->filled('currency')) {
            $request->merge([
                'currency' => SupportedCurrencies::normalize((string) $request->input('currency')),
            ]);
        }

        $validated = $request->validate([
            'site_id'                  => ['required', 'integer', 'exists:sites,id'],
            'amount'                   => ['required', 'numeric', 'min:0'],
            'currency'                 => SupportedCurrencies::rules(required: false),
            'allow_currency_mismatch'  => ['sometimes', 'boolean'],
        ]);

        $billing = Setting::billing();
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

        $sitePrice = DB::transaction(function () use ($unitClass, $validated, $createdBy, $today, $site, $currency) {
            $rate = UnitClassRate::query()->firstOrCreate(
                [
                    'unit_class_id' => $unitClass->id,
                    'site_id'       => $validated['site_id'],
                ],
            );

            $current = $rate->price()->first();
            $oldPriceId = $current?->id;

            if ($current !== null) {
                $current->update(['effective_to' => $today]);
            }

            $price = Price::query()->create([
                'priceable_type' => 'unit_class_rate',
                'priceable_id'   => $rate->id,
                'scope'          => Price::SCOPE_CATALOGUE,
                'amount'         => $validated['amount'],
                'currency'       => $currency,
                'effective_from' => $today,
                'effective_to'   => null,
                'created_by'     => $createdBy,
            ]);

            if ($unitClass->current_price_id === null || $unitClass->current_price_id === $oldPriceId) {
                $unitClass->update(['current_price_id' => $price->id]);
            }

            RecordsActivity::log(LogChannel::Facility, 'rate.changed', $unitClass, [
                'unit_class_id' => $unitClass->id,
                'site_id' => $validated['site_id'],
                'old_price_id' => $oldPriceId,
                'new_price_id' => $price->id,
                'amount' => (string) $price->amount,
                'currency' => $price->currency,
            ]);

            return $this->formatSitePrice($site, $rate->fresh(), $price);
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
        ];
    }
}
