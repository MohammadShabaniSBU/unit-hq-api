<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Models\Site;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;

/**
 * Matches SVG unit shapes against a site's `units.unit_number`.
 *
 * Primary join key is `data-unit-number`. When a document contains at least one
 * non-empty `data-unit-number`, that set is the shape set and element `id`
 * values are ignored (structural ids like `row-3` / `layer1` must not appear
 * as orphan shapes). Maps with no `data-unit-number` anywhere fall back to
 * matching element `id`, for plans drawn against the pre-S20 convention.
 *
 * See docs/02-facility.md.
 */
final class SiteMapIdMatcher
{
    /**
     * @return array{matched: array<int, string>, orphan_shapes: array<int, string>, uncovered_units: array<int, string>}
     */
    public static function match(Site $site, string $svg): array
    {
        $document = self::loadDocument($svg);

        if ($document === null) {
            return [
                'matched' => [],
                'orphan_shapes' => [],
                'uncovered_units' => [],
            ];
        }

        $shapeIds = self::extractIdsFromDocument($document);
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
        $document = self::loadDocument($svg);

        if ($document === null) {
            return [];
        }

        return self::extractIdsFromDocument($document);
    }

    private static function loadDocument(string $svg): ?DOMDocument
    {
        $svg = trim($svg);

        if ($svg === '') {
            return null;
        }

        $document = new DOMDocument;

        $previousErrorSetting = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        return $loaded ? $document : null;
    }

    /** @return array<int, string> */
    private static function extractIdsFromDocument(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);

        $dataRefs = self::collectAttributeValues($xpath->query('//*[@data-unit-number]'), 'data-unit-number');

        if ($dataRefs !== []) {
            return $dataRefs;
        }

        return self::collectAttributeValues($xpath->query('//*[@id]'), 'id');
    }

    /**
     * @return array<int, string>
     */
    private static function collectAttributeValues(DOMNodeList|false $nodes, string $attribute): array
    {
        if ($nodes === false) {
            return [];
        }

        $values = [];

        foreach ($nodes as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $value = trim($element->getAttribute($attribute));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
