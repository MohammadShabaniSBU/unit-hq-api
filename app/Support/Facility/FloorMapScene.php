<?php

declare(strict_types=1);

namespace App\Support\Facility;

use DOMDocument;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes a floor-map builder scene.
 *
 * The scene is the builder source of truth; FloorMapCompiler turns it into
 * the SVG the operational unit map consumes.
 *
 * @phpstan-type ViewBox array{width: float, height: float}
 * @phpstan-type UnitItem array{id: string, type: 'unit', x: float, y: float, width: float, height: float, unit_number: string|null}
 * @phpstan-type EntranceItem array{id: string, type: 'entrance', x: float, y: float, width: float, height: float}
 * @phpstan-type Scene array{version: int, viewBox: ViewBox, backgroundSvg: string|null, items: array<int, UnitItem|EntranceItem>}
 */
final class FloorMapScene
{
    public const VERSION = 1;

    public const MAX_ITEMS = 2000;

    public const MAX_VIEW = 20000.0;

    public const MIN_SIZE = 8.0;

    public const MAX_BACKGROUND_BYTES = 524288;

    /**
     * @return Scene
     */
    public static function normalize(mixed $scene): array
    {
        if (! is_array($scene)) {
            throw ValidationException::withMessages([
                'scene' => ['The scene must be an object.'],
            ]);
        }

        $version = $scene['version'] ?? null;

        if ((int) $version !== self::VERSION) {
            throw ValidationException::withMessages([
                'scene.version' => ['Unsupported floor map scene version.'],
            ]);
        }

        $viewBox = $scene['viewBox'] ?? null;

        if (! is_array($viewBox)) {
            throw ValidationException::withMessages([
                'scene.viewBox' => ['The scene viewBox is required.'],
            ]);
        }

        $width = self::coord($viewBox['width'] ?? null, 'scene.viewBox.width');
        $height = self::coord($viewBox['height'] ?? null, 'scene.viewBox.height');

        if ($width < 1 || $height < 1 || $width > self::MAX_VIEW || $height > self::MAX_VIEW) {
            throw ValidationException::withMessages([
                'scene.viewBox' => ['The scene viewBox is out of range.'],
            ]);
        }

        $itemsInput = $scene['items'] ?? null;

        if (! is_array($itemsInput)) {
            throw ValidationException::withMessages([
                'scene.items' => ['The scene items must be an array.'],
            ]);
        }

        if (count($itemsInput) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'scene.items' => ['The scene has too many items.'],
            ]);
        }

        $items = [];
        $ids = [];
        $assignedNumbers = [];

        foreach (array_values($itemsInput) as $index => $rawItem) {
            $item = self::normalizeItem($rawItem, $index);

            if (isset($ids[$item['id']])) {
                throw ValidationException::withMessages([
                    "scene.items.{$index}.id" => ['Each scene item id must be unique.'],
                ]);
            }

            $ids[$item['id']] = true;

            if ($item['type'] === 'unit' && $item['unit_number'] !== null) {
                if (isset($assignedNumbers[$item['unit_number']])) {
                    throw ValidationException::withMessages([
                        "scene.items.{$index}.unit_number" => ['Each unit can be placed at most once on this floor.'],
                    ]);
                }

                $assignedNumbers[$item['unit_number']] = true;
            }

            $items[] = $item;
        }

        return [
            'version' => self::VERSION,
            'viewBox' => [
                'width' => $width,
                'height' => $height,
            ],
            'backgroundSvg' => self::normalizeBackground($scene['backgroundSvg'] ?? null),
            'items' => $items,
        ];
    }

    /**
     * @return UnitItem|EntranceItem
     */
    private static function normalizeItem(mixed $rawItem, int $index): array
    {
        if (! is_array($rawItem)) {
            throw ValidationException::withMessages([
                "scene.items.{$index}" => ['Each scene item must be an object.'],
            ]);
        }

        $id = trim((string) ($rawItem['id'] ?? ''));

        if ($id === '' || strlen($id) > 64) {
            throw ValidationException::withMessages([
                "scene.items.{$index}.id" => ['Each scene item needs a stable id.'],
            ]);
        }

        $type = $rawItem['type'] ?? null;

        if ($type !== 'unit' && $type !== 'entrance') {
            throw ValidationException::withMessages([
                "scene.items.{$index}.type" => ['Unknown map item type.'],
            ]);
        }

        $x = self::coord($rawItem['x'] ?? null, "scene.items.{$index}.x");
        $y = self::coord($rawItem['y'] ?? null, "scene.items.{$index}.y");
        $width = self::coord($rawItem['width'] ?? null, "scene.items.{$index}.width");
        $height = self::coord($rawItem['height'] ?? null, "scene.items.{$index}.height");

        if ($width < self::MIN_SIZE || $height < self::MIN_SIZE) {
            throw ValidationException::withMessages([
                "scene.items.{$index}" => ['Map items must be at least 8px wide and tall.'],
            ]);
        }

        if ($type === 'entrance') {
            return [
                'id' => $id,
                'type' => 'entrance',
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
            ];
        }

        $unitNumber = $rawItem['unit_number'] ?? null;

        if ($unitNumber !== null) {
            $unitNumber = trim((string) $unitNumber);
            $unitNumber = $unitNumber === '' ? null : $unitNumber;
        }

        if ($unitNumber !== null && strlen($unitNumber) > 255) {
            throw ValidationException::withMessages([
                "scene.items.{$index}.unit_number" => ['The unit number is too long.'],
            ]);
        }

        return [
            'id' => $id,
            'type' => 'unit',
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'unit_number' => $unitNumber,
        ];
    }

    private static function coord(mixed $value, string $key): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $key => ['A numeric value is required.'],
            ]);
        }

        return (float) $value;
    }

    private static function normalizeBackground(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            throw ValidationException::withMessages([
                'scene.backgroundSvg' => ['backgroundSvg must be a string or null.'],
            ]);
        }

        $fragment = trim($raw);

        if ($fragment === '') {
            return null;
        }

        if (strlen($fragment) > self::MAX_BACKGROUND_BYTES) {
            throw ValidationException::withMessages([
                'scene.backgroundSvg' => ['The imported background is too large.'],
            ]);
        }

        $wrapped = '<svg xmlns="http://www.w3.org/2000/svg">'.$fragment.'</svg>';

        try {
            $clean = SvgSanitizer::sanitize($wrapped);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'scene.backgroundSvg' => ['The imported background could not be parsed as SVG.'],
            ]);
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($clean);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            return null;
        }

        $inner = '';

        foreach ($document->documentElement->childNodes as $child) {
            $inner .= $document->saveXML($child);
        }

        $inner = trim($inner);

        return $inner === '' ? null : $inner;
    }
}
