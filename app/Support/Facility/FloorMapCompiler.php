<?php

declare(strict_types=1);

namespace App\Support\Facility;

/**
 * Compiles a builder scene into the SVG stored on site_maps.svg_map.
 *
 * Markup contract matches FloorPlanGenerator / the panel matcher:
 * assigned units are <g class="storage-unit" data-unit-number="…">.
 * Unassigned units stay in the scene only and are not emitted.
 * Status colours are never baked in (invariant 5).
 */
final class FloorMapCompiler
{
    /**
     * @param  array{
     *     version: int,
     *     viewBox: array{width: float, height: float},
     *     backgroundSvg: string|null,
     *     items: array<int, array<string, mixed>>
     * }  $scene
     */
    public static function render(string $floorName, array $scene): string
    {
        $width = $scene['viewBox']['width'];
        $height = $scene['viewBox']['height'];
        $viewBox = '0 0 '.self::n($width).' '.self::n($height);
        $title = self::esc($floorName);

        $body = [];

        if (is_string($scene['backgroundSvg']) && $scene['backgroundSvg'] !== '') {
            $body[] = "  <g class=\"map-background\">\n".$scene['backgroundSvg']."\n  </g>";
        }

        foreach ($scene['items'] as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'unit') {
                $rendered = self::renderUnit($item);

                if ($rendered !== null) {
                    $body[] = $rendered;
                }

                continue;
            }

            if ($type === 'entrance') {
                $body[] = self::renderEntrance($item);
            }
        }

        $contents = implode("\n\n", $body);

        return <<<SVG
<svg viewBox="{$viewBox}" font-family="Arial, Helvetica, sans-serif" version="1.1" xmlns="http://www.w3.org/2000/svg">
  <title>{$title}</title>
  <style>
    .unit { stroke: #000; stroke-width: 1.5; fill: #ffffff; }
    .unit:hover { fill: #fff7cc; cursor: pointer; }
    .storage-unit[data-status="available"]   .unit { fill: #ffffff; }
    .storage-unit[data-status="occupied"]    .unit { fill: #dfe7f3; }
    .storage-unit[data-status="reserved"]    .unit { fill: #fff3cd; }
    .storage-unit[data-status="maintenance"] .unit { fill: #d6d6d6; }
    .storage-unit[data-status="damaged"]     .unit { fill: #f8d7da; }
    .storage-unit[data-status="staff_use"]   .unit { fill: #e4e4e4; }
    .storage-unit[data-status="other"]       .unit { fill: #ededed; }
    .storage-unit[data-overdue="1"]  .unit { fill: #ff8a8a; }
    .storage-unit[data-overlock="1"] .unit { stroke: #cc0000; stroke-width: 3; }
    .unit-label { font-size: 10px; font-weight: bold; fill: #000; text-anchor: middle; pointer-events: none; }
    .core-outline { fill: #ffffff; stroke: #000; stroke-width: 2; }
    .core-label { font-size: 9px; font-weight: bold; fill: #000; text-anchor: middle; }
    .map-background { pointer-events: none; }
  </style>

{$contents}
</svg>
SVG;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function renderUnit(array $item): ?string
    {
        $unitNumber = $item['unit_number'] ?? null;

        if (! is_string($unitNumber) || $unitNumber === '') {
            return null;
        }

        $x = (float) $item['x'];
        $y = (float) $item['y'];
        $w = (float) $item['width'];
        $h = (float) $item['height'];
        $cx = $x + ($w / 2);
        $cy = $y + ($h / 2);
        $number = self::esc($unitNumber);
        $rotate = $w < 46.0;

        $inner = [sprintf(
            '    <rect class="unit" x="%s" y="%s" width="%s" height="%s" />',
            self::n($x),
            self::n($y),
            self::n($w),
            self::n($h)
        )];

        if ($rotate) {
            $inner[] = sprintf(
                '    <text class="unit-label" x="%s" y="%s" transform="rotate(-90,%s,%s)">%s</text>',
                self::n($cx),
                self::n($cy + 3),
                self::n($cx),
                self::n($cy + 3),
                $number
            );
        } else {
            $inner[] = sprintf(
                '    <text class="unit-label" x="%s" y="%s">%s</text>',
                self::n($cx),
                self::n($cy + 3.5),
                $number
            );
        }

        return sprintf(
            "  <g class=\"storage-unit\" id=\"%s\" data-unit-number=\"%s\">\n%s\n  </g>",
            $number,
            $number,
            implode("\n", $inner)
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function renderEntrance(array $item): string
    {
        $x = (float) $item['x'];
        $y = (float) $item['y'];
        $w = (float) $item['width'];
        $h = (float) $item['height'];
        $cx = $x + ($w / 2);
        $xmlId = self::xmlId((string) ($item['id'] ?? 'entrance'));

        return implode("\n", [
            sprintf('  <g class="map-item map-item--entrance" id="%s" data-map-item="entrance">', $xmlId),
            sprintf(
                '    <rect class="core-outline" x="%s" y="%s" width="%s" height="%s" />',
                self::n($x),
                self::n($y),
                self::n($w),
                self::n($h)
            ),
            sprintf(
                '    <text class="core-label" x="%s" y="%s">ENTRANCE</text>',
                self::n($cx),
                self::n($y + $h - 10)
            ),
            '  </g>',
        ]);
    }

    private static function xmlId(string $id): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) ?? 'entrance';

        if ($safe === '' || ! preg_match('/^[A-Za-z_]/', $safe)) {
            $safe = 'item-'.$safe;
        }

        return $safe;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
