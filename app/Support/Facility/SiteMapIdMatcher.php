<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Models\Site;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Matches SVG element `id` attributes against a site's `units.unit_number`,
 * per the id-matching convention documented in docs/02-facility.md.
 */
final class SiteMapIdMatcher
{
    /**
     * @return array{matched: array<int, string>, orphan_shapes: array<int, string>, uncovered_units: array<int, string>}
     */
    public static function match(Site $site, string $svg): array
    {
        $shapeIds = self::extractIds($svg);
        $unitNumbers = $site->units()->pluck('unit_number')->all();

        $matched = array_values(array_intersect($shapeIds, $unitNumbers));
        $orphanShapes = array_values(array_diff($shapeIds, $unitNumbers));
        $uncoveredUnits = array_values(array_diff($unitNumbers, $shapeIds));

        sort($matched);
        sort($orphanShapes);
        sort($uncoveredUnits);

        return [
            'matched' => $matched,
            'orphan_shapes' => $orphanShapes,
            'uncovered_units' => $uncoveredUnits,
        ];
    }

    /** @return array<int, string> */
    public static function extractIds(string $svg): array
    {
        $svg = trim($svg);

        if ($svg === '') {
            return [];
        }

        $document = new DOMDocument;

        $previousErrorSetting = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[@id]');

        if ($nodes === false) {
            return [];
        }

        $ids = [];

        foreach ($nodes as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $id = trim($element->getAttribute('id'));

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
