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
}
