<?php

declare(strict_types=1);

namespace App\Support\Country;

use App\Models\DelinquencyPolicy;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Site;
use App\Models\TaxRate;

/**
 * Lists rows that contradict the current CountryProfile.
 *
 * @phpstan-type AuditRow array{kind: string, id: int, detail: string}
 */
final class CountryAudit
{
    /**
     * @return Array<int, array{kind: string, id: int, detail: string}>
     */
    public static function contradictions(): array
    {
        $profile = CountryProfiles::current();
        $rows = [];

        if (CountryProfiles::isLockedMismatch()) {
            $identity = CountryProfiles::identity();
            $rows[] = [
                'kind' => 'env',
                'id' => 0,
                'detail' => sprintf(
                    'KEEVARIS_COUNTRY=%s but deployment_identity is locked at %s',
                    config('deployment.country'),
                    $identity?->country_code ?? 'unknown',
                ),
            ];
        }

        $countryId = null;
        try {
            $countryId = CountryProfiles::countryId();
        } catch (UnknownCountryException) {
            // Country row missing — sites cannot be compared by id.
        }

        if ($countryId !== null) {
            Site::query()->where('country_id', '!=', $countryId)->each(function (Site $site) use (&$rows): void {
                $rows[] = [
                    'kind' => 'site',
                    'id' => $site->id,
                    'detail' => "site #{$site->id} {$site->name} country_id={$site->country_id}",
                ];
            });

            Site::query()
                ->whereNotIn('timezone', $profile->allowedTimezones())
                ->each(function (Site $site) use (&$rows): void {
                    $rows[] = [
                        'kind' => 'site_timezone',
                        'id' => $site->id,
                        'detail' => "site #{$site->id} timezone={$site->timezone}",
                    ];
                });
        }

        LegalEntity::query()
            ->where('country_code', '!=', $profile->code())
            ->each(function (LegalEntity $entity) use (&$rows): void {
                $rows[] = [
                    'kind' => 'legal_entity',
                    'id' => $entity->id,
                    'detail' => "legal_entity #{$entity->id} country_code={$entity->country_code}",
                ];
            });

        $allowedJurisdictions = array_merge([$profile->code()], $profile->taxSubdivisions());
        TaxRate::query()
            ->whereNotNull('jurisdiction')
            ->whereNotIn('jurisdiction', $allowedJurisdictions)
            ->each(function (TaxRate $rate) use (&$rows): void {
                $rows[] = [
                    'kind' => 'tax_rate',
                    'id' => $rate->id,
                    'detail' => "tax_rate #{$rate->id} {$rate->code} jurisdiction={$rate->jurisdiction}",
                ];
            });

        Price::query()
            ->where('currency', '!=', $profile->currency())
            ->each(function (Price $price) use (&$rows, $profile): void {
                $rows[] = [
                    'kind' => 'price',
                    'id' => $price->id,
                    'detail' => "price #{$price->id} currency={$price->currency} (country {$profile->currency()})",
                ];
            });

        if (self::hasJurisdictionColumn()) {
            DelinquencyPolicy::query()
                ->whereNotNull('jurisdiction')
                ->where('jurisdiction', '!=', $profile->code())
                ->each(function (DelinquencyPolicy $policy) use (&$rows): void {
                    $rows[] = [
                        'kind' => 'delinquency_policy',
                        'id' => $policy->id,
                        'detail' => "delinquency_policy #{$policy->id} {$policy->name} jurisdiction={$policy->jurisdiction}",
                    ];
                });
        }

        return $rows;
    }

    private static function hasJurisdictionColumn(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('delinquency_policies', 'jurisdiction');
    }
}
