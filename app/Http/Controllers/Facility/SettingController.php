<?php

namespace App\Http\Controllers\Facility;

use App\Enums\LogChannel;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'default_currency'               => ['sometimes', 'required', 'string', 'size:3'],
            'default_billing_interval'       => ['sometimes', 'required', 'string', Rule::in(['day', 'week', 'month'])],
            'default_billing_interval_count' => ['sometimes', 'required', 'integer', 'min:1'],
            'billing_anchor_model'           => ['sometimes', 'required', 'string', Rule::in(['anniversary', 'calendar', 'calendar_week'])],
            'billing_anchor_day'             => ['sometimes', 'required', 'integer', 'min:1', 'max:28'],
            'proration_method'               => ['sometimes', 'required', 'string', Rule::in(['daily', 'full_period', 'none'])],
            'default_deposit_amount'         => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $anchorModel = $validated['billing_anchor_model'] ?? Setting::billing()->billingAnchorModel;
        $interval = $validated['default_billing_interval'] ?? Setting::billing()->defaultBillingInterval;

        if ($anchorModel === 'calendar' && $interval !== 'month') {
            throw ValidationException::withMessages([
                'billing_anchor_model' => ['The calendar anchor model requires a monthly billing interval.'],
            ]);
        }

        if ($anchorModel === 'calendar_week' && $interval !== 'week') {
            throw ValidationException::withMessages([
                'billing_anchor_model' => ['The calendar week anchor model requires a weekly billing interval.'],
            ]);
        }

        if (isset($validated['billing_anchor_day']) && $anchorModel === 'calendar_week' && $validated['billing_anchor_day'] > 7) {
            throw ValidationException::withMessages([
                'billing_anchor_day' => ['The calendar week anchor day must be between 1 (Monday) and 7 (Sunday).'],
            ]);
        }

        Setting::setBilling(
            Setting::billing()->with(
                defaultCurrency: $validated['default_currency'] ?? null,
                defaultBillingInterval: $validated['default_billing_interval'] ?? null,
                defaultBillingIntervalCount: $validated['default_billing_interval_count'] ?? null,
                billingAnchorModel: $validated['billing_anchor_model'] ?? null,
                billingAnchorDay: $validated['billing_anchor_day'] ?? null,
                prorationMethod: $validated['proration_method'] ?? null,
                defaultDepositAmount: isset($validated['default_deposit_amount'])
                    ? number_format((float) $validated['default_deposit_amount'], 2, '.', '')
                    : null,
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

    public function showActivityLog(): JsonResponse
    {
        return $this->success(
            Setting::activityLog()->toArray(),
            'Activity log settings retrieved successfully.'
        );
    }

    public function updateActivityLog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channels' => ['sometimes', 'required', 'array'],
            'channels.*' => ['string', Rule::in(LogChannel::optionalValues())],
            'retention_months' => ['sometimes', 'required', 'integer', 'min:3', 'max:60'],
        ]);

        Setting::setActivityLog(
            Setting::activityLog()->with(
                channels: $validated['channels'] ?? null,
                retentionMonths: $validated['retention_months'] ?? null,
            )
        );

        return $this->success(
            Setting::activityLog()->toArray(),
            'Activity log settings updated successfully.'
        );
    }
}
