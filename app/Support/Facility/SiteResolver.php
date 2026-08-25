<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use Illuminate\Support\Collection;

final class SiteResolver
{
    /**
     * @return list<SiteMatch>
     */
    public static function resolve(?string $query, ?float $lat, ?float $lng, int $limit = 5): array
    {
        $limit = max(1, $limit);
        $query = $query !== null ? trim($query) : null;
        if ($query === '') {
            $query = null;
        }
        $hasCoords = $lat !== null && $lng !== null;

        $active = Site::query()->active()->orderBy('id')->get();
        if ($active->isEmpty()) {
            return [];
        }

        if ($query === null && ! $hasCoords && $active->count() === 1) {
            return [new SiteMatch($active->first(), SiteMatchReason::OnlySite)];
        }

        if ($query !== null) {
            $matched = self::matchQuery($active, $query);
            if ($matched !== []) {
                return array_slice($matched, 0, $limit);
            }
        }

        if ($hasCoords) {
            $byDistance = self::matchDistance($active, $lat, $lng, $limit);
            if ($byDistance !== []) {
                return $byDistance;
            }
        }

        return $active
            ->take($limit)
            ->map(fn (Site $site): SiteMatch => new SiteMatch($site, SiteMatchReason::NoMatch))
            ->all();
    }

    /**
     * @param  Collection<int, Site>  $active
     * @return list<SiteMatch>
     */
    private static function matchQuery(Collection $active, string $query): array
    {
        $digits = self::digits($query);
        $ids = $active->pluck('id');

        $areas = SiteServiceArea::query()
            ->active()
            ->whereIn('site_id', $ids)
            ->get()
            ->groupBy(fn (SiteServiceArea $area): string => $area->kind->value);

        $exact = $areas->get(SiteServiceAreaKind::Postcode->value, collect())
            ->filter(function (SiteServiceArea $area) use ($digits, $query): bool {
                if ($digits !== '' && self::digits($area->value) === $digits) {
                    return true;
                }

                return strcasecmp($area->value, $query) === 0;
            });
        $regions = $areas->get(SiteServiceAreaKind::AdminRegion->value, collect())
            ->filter(fn (SiteServiceArea $area): bool => strcasecmp($area->value, $query) === 0);

        $serviceHits = $exact->concat($regions);
        if ($serviceHits->isNotEmpty()) {
            return self::sitesForAreas($active, $serviceHits, SiteMatchReason::ServiceArea);
        }

        if ($digits !== '') {
            $prefixes = $areas->get(SiteServiceAreaKind::PostcodePrefix->value, collect())
                ->filter(function (SiteServiceArea $area) use ($digits): bool {
                    $prefix = self::digits($area->value);
                    if ($prefix === '') {
                        return false;
                    }

                    return str_starts_with($digits, $prefix);
                });
            if ($prefixes->isNotEmpty()) {
                $longest = (int) $prefixes->max(fn (SiteServiceArea $area): int => strlen(self::digits($area->value)));
                $prefixes = $prefixes->filter(
                    fn (SiteServiceArea $area): bool => strlen(self::digits($area->value)) === $longest,
                );

                return self::sitesForAreas($active, $prefixes, SiteMatchReason::ServiceAreaPrefix);
            }
        }

        $byPostcode = $active->filter(function (Site $site) use ($digits, $query): bool {
            if ($site->postal_code === null || $site->postal_code === '') {
                return false;
            }
            if ($digits !== '' && self::digits($site->postal_code) === $digits) {
                return true;
            }

            return strcasecmp($site->postal_code, $query) === 0;
        });
        if ($byPostcode->isNotEmpty()) {
            return $byPostcode
                ->map(fn (Site $site): SiteMatch => new SiteMatch($site, SiteMatchReason::SitePostcode))
                ->values()
                ->all();
        }

        $byLocality = $active->filter(function (Site $site) use ($query): bool {
            foreach ([$site->city, $site->state_region] as $field) {
                if ($field !== null && $field !== '' && strcasecmp($field, $query) === 0) {
                    return true;
                }
            }

            return false;
        });
        if ($byLocality->isNotEmpty()) {
            return $byLocality
                ->map(fn (Site $site): SiteMatch => new SiteMatch($site, SiteMatchReason::Locality))
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  Collection<int, Site>  $active
     * @param  Collection<int, SiteServiceArea>  $areas
     * @return list<SiteMatch>
     */
    private static function sitesForAreas(Collection $active, Collection $areas, SiteMatchReason $reason): array
    {
        $siteIds = $areas->pluck('site_id')->unique()->all();

        return $active
            ->whereIn('id', $siteIds)
            ->map(fn (Site $site): SiteMatch => new SiteMatch($site, $reason))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Site>  $active
     * @return list<SiteMatch>
     */
    private static function matchDistance(Collection $active, float $lat, float $lng, int $limit): array
    {
        $ranked = $active
            ->filter(fn (Site $site): bool => $site->latitude !== null && $site->longitude !== null)
            ->map(function (Site $site) use ($lat, $lng): SiteMatch {
                $km = self::haversine(
                    $lat,
                    $lng,
                    (float) $site->latitude,
                    (float) $site->longitude,
                );

                return new SiteMatch($site, SiteMatchReason::Distance, round($km, 1));
            })
            ->sortBy(fn (SiteMatch $match): float => $match->distanceKm ?? 0)
            ->take($limit)
            ->values()
            ->all();

        return $ranked;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthKm * asin(min(1, sqrt($a)));
    }
}
