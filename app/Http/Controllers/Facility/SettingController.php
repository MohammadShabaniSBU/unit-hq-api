<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function showGeneral(): JsonResponse
    {
        return $this->success(
            Setting::general()->toArray(),
            'General settings retrieved successfully.'
        );
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name'          => ['sometimes', 'required', 'string', 'max:255'],
            'company_contact_email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone'                 => ['sometimes', 'required', 'string', 'max:50'],
        ]);

        Setting::setGeneral(
            Setting::general()->with(
                companyName: $validated['company_name'] ?? null,
                companyContactEmail: $validated['company_contact_email'] ?? null,
                phone: $validated['phone'] ?? null,
            )
        );

        return $this->success(
            Setting::general()->toArray(),
            'General settings updated successfully.'
        );
    }

    public function showBilling(): JsonResponse
    {
        return $this->success(
            Setting::billing()->toArray(),
            'Billing settings retrieved successfully.'
        );
    }

    public function updateBilling(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_currency'       => ['sometimes', 'required', 'string', 'size:3'],
            'default_billing_period' => ['sometimes', 'required', 'string', Rule::in(['monthly', 'weekly', 'annual'])],
        ]);

        Setting::setBilling(
            Setting::billing()->with(
                defaultCurrency: $validated['default_currency'] ?? null,
                defaultBillingPeriod: $validated['default_billing_period'] ?? null,
            )
        );

        return $this->success(
            Setting::billing()->toArray(),
            'Billing settings updated successfully.'
        );
    }

    public function showLeasing(): JsonResponse
    {
        return $this->success(
            Setting::leasing()->toArray(),
            'Leasing settings retrieved successfully.'
        );
    }

    public function updateLeasing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_offer_expiration_value'       => ['sometimes', 'required', 'integer', 'min:1'],
            'default_offer_expiration_unit'        => ['sometimes', 'required', 'string', Rule::in(['minutes', 'hours', 'days', 'weeks'])],
            'default_reservation_expiration_value' => ['sometimes', 'required', 'integer', 'min:1'],
            'default_reservation_expiration_unit'  => ['sometimes', 'required', 'string', Rule::in(['minutes', 'hours', 'days', 'weeks'])],
        ]);

        Setting::setLeasing(
            Setting::leasing()->with(
                defaultOfferExpirationValue: $validated['default_offer_expiration_value'] ?? null,
                defaultOfferExpirationUnit: $validated['default_offer_expiration_unit'] ?? null,
                defaultReservationExpirationValue: $validated['default_reservation_expiration_value'] ?? null,
                defaultReservationExpirationUnit: $validated['default_reservation_expiration_unit'] ?? null,
            )
        );

        return $this->success(
            Setting::leasing()->toArray(),
            'Leasing settings updated successfully.'
        );
    }
}
