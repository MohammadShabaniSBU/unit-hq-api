<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Models\MarketingTouch;
use App\Models\SignupForm;
use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Auth\Permission;
use App\Support\Marketing\SignupFormPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdvertisingSetupController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingView->value);

        $accounts = AdPlatformAccount::query()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->success([
            'accounts_connected' => $accounts->isNotEmpty(),
            'tag_seen' => MarketingTouch::query()
                ->where('occurred_at', '>=', now()->subDays(7))
                ->exists(),
            'enquiry_form' => SignupForm::query()
                ->where('purpose', SignupFormPurpose::Enquiry)
                ->whereNull('archived_at')
                ->exists(),
            'mappings_set' => $this->mappingsSet(),
            'campaigns_mapped' => ! AdCampaign::query()
                ->where('status', AdCampaignStatus::Enabled)
                ->whereNull('site_id')
                ->exists(),
            'accounts' => $accounts->map(fn (AdPlatformAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider->value,
                'currency' => $account->currency,
                'is_active' => (bool) $account->is_active,
            ])->values()->all(),
        ]);
    }

    public function tagProbe(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        $validated = $request->validate([
            'gclid' => ['required', 'string', 'max:255'],
        ]);

        $touch = MarketingTouch::query()
            ->where('click_ids->gclid', $validated['gclid'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $this->success([
            'found' => $touch !== null,
            'occurred_at' => $touch?->occurred_at?->toDateTimeString(),
        ]);
    }

    private function mappingsSet(): bool
    {
        $accountIds = AdPlatformAccount::query()->eligible()->pluck('id');

        if ($accountIds->isEmpty()) {
            return true;
        }

        $needed = [
            ConversionEventKey::Lead->value,
            ConversionEventKey::Reservation->value,
            ConversionEventKey::MoveIn->value,
        ];

        $present = AdConversionMapping::query()
            ->whereIn('ad_platform_account_id', $accountIds)
            ->get(['ad_platform_account_id', 'event_key'])
            ->groupBy('ad_platform_account_id');

        foreach ($accountIds as $accountId) {
            $keys = $present->get($accountId, collect())
                ->map(fn (AdConversionMapping $row): string => $row->event_key->value)
                ->all();

            foreach ($needed as $key) {
                if (! in_array($key, $keys, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
