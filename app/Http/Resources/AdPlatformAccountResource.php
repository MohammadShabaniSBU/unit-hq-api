<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Advertising\AdPlatformRegistry;
use App\Support\Advertising\Contracts\ListsCampaigns;
use App\Support\Advertising\Contracts\ListsConversionTargets;
use App\Support\Advertising\Contracts\ReportsConversions;
use App\Support\Advertising\Contracts\ReportsSpend;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdPlatformAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $registry = app(AdPlatformRegistry::class);
        $fields = [];
        $reportsConversions = false;
        $reportsSpend = false;
        $listsCampaigns = false;
        $listsConversionTargets = false;

        try {
            $class = $registry->adapterClass($this->provider);
            $fields = $class::make([])->credentialFields();
            $reportsConversions = is_subclass_of($class, ReportsConversions::class);
            $reportsSpend = is_subclass_of($class, ReportsSpend::class);
            $listsCampaigns = is_subclass_of($class, ListsCampaigns::class);
            $listsConversionTargets = is_subclass_of($class, ListsConversionTargets::class);
        } catch (InvalidArgumentException) {
            $fields = [];
        }

        $credentials = CredentialMasker::readSafely($this->resource, 'credentials');
        $credentials = is_array($credentials) ? $credentials : null;
        $unreadable = $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'credentials');

        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'name' => $this->name,
            'external_account_id' => $this->external_account_id,
            'login_customer_id' => $this->login_customer_id,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'is_active' => (bool) $this->is_active,
            'credentials' => $unreadable ? [] : CredentialMasker::maskFields($credentials, $fields),
            'credentials_unreadable' => $unreadable,
            'reports_conversions' => $reportsConversions,
            'reports_spend' => $reportsSpend,
            'lists_campaigns' => $listsCampaigns,
            'lists_conversion_targets' => $listsConversionTargets,
            'last_verified_at' => $this->datetime($this->last_verified_at),
            'last_error' => $this->last_error,
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
