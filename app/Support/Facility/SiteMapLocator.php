<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Models\OfferOption;
use App\Models\SiteMap;
use App\Models\Unit;

/**
 * Resolves the floor plan whose SVG contains a given unit.
 *
 * A site may have several `site_maps` (one per floor). Identity is the same
 * join as upload validation: `data-unit-number` (legacy fallback: element `id`).
 * See SiteMapIdMatcher and docs/02-facility.md.
 */
final class SiteMapLocator
{
    public static function findForUnit(Unit $unit): ?SiteMap
    {
        $maps = SiteMap::query()
            ->where('site_id', $unit->site_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($maps as $map) {
            if (in_array($unit->unit_number, SiteMapIdMatcher::extractIds($map->svg_map), true)) {
                return $map;
            }
        }

        return null;
    }

    /**
     * @return array{floor_name: string, svg_map: string, unit_number: string, site_name: string|null}|null
     */
    public static function payloadForOption(OfferOption $option): ?array
    {
        $option->loadMissing(['unit.site']);
        $unit = $option->unit;

        if ($unit === null) {
            return null;
        }

        $map = self::findForUnit($unit);

        if ($map === null) {
            return null;
        }

        return [
            'floor_name' => $map->floor_name,
            'svg_map' => $map->svg_map,
            'unit_number' => $unit->unit_number,
            'site_name' => $unit->site?->name,
        ];
    }
}
