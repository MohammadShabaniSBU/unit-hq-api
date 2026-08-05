<?php

declare(strict_types=1);

namespace App\Support\Facility;

/**
 * Renders a site floor plan SVG from a list of units.
 *
 * The output is the same shape as hand-authored plans: every unit is a
 * <g class="storage-unit"> carrying BOTH id="{unit_number}" and
 * data-unit-number="{unit_number}", so the id-matching convention in
 * docs/02-facility.md and the data-attribute convention both resolve.
 *
 * Deliberately NOT emitted: data-status. Unit state is derived at read time
 * (invariant 5) — the panel stamps data-status from Availability::stateOn()
 * after fetching the map. A status baked into a stored SVG is stale the moment
 * a contract is signed.
 *
 * Same tier as App\Support\Billing\* — static helpers, no state, no Services layer.
 */
final class FloorPlanGenerator
{
    /** Pixels per metre. 26 matches the hand-authored plans (3m unit ≈ 78px). */
    public const SCALE = 26.0;

    /** Usable interior width of the building, in px. */
    public const CONTENT_WIDTH = 1120.0;

    public const AISLE = 66.0;
    public const MARGIN = 48.0;
    public const MIN_ROW_DEPTH = 68.0;

    /** Width of the entrance vestibule / lift core hung off the left wall. */
    public const CORE_WIDTH = 88.0;

    /**
     * @param  string  $floorName    e.g. "Ground floor"
     * @param  array<int, array{unit_number: string, width_m: float|string, depth_m: float|string}>  $units
     * @param  array{
     *     entry?: bool,                 // draw entrance vestibule (ground floor) or lift core (upper)
     *     orphan_shapes?: array<int, string>,  // extra ids with no unit — for validator testing only
     *     size_labels?: bool
     * }  $options
     */
    public static function render(string $floorName, array $units, array $options = []): string
    {
        $entry       = $options['entry'] ?? true;
        $orphans     = $options['orphan_shapes'] ?? [];
        $sizeLabels  = $options['size_labels'] ?? true;

        $prepared = self::prepare($units);
        $rows     = self::packRows($prepared);
        $bands    = self::layoutBands($rows);

        // The entrance vestibule / lift core hangs off the left wall, so the
        // canvas needs a gutter wide enough to contain it.
        $x0 = self::MARGIN + self::CORE_WIDTH;
        $y0 = self::MARGIN;

        $interiorHeight = 0.0;
        foreach ($bands as $band) {
            $interiorHeight += $band['height'];
        }

        $annexHeight = $orphans === [] ? 0.0 : 128.0;
        $svgWidth    = $x0 + self::CONTENT_WIDTH + self::MARGIN;
        $svgHeight   = $interiorHeight + (self::MARGIN * 2) + $annexHeight;

        $body = [];

        // --- building shell -------------------------------------------------
        $body[] = sprintf(
            '  <rect class="building-outline" x="%s" y="%s" width="%s" height="%s" />',
            self::n($x0), self::n($y0), self::n(self::CONTENT_WIDTH), self::n($interiorHeight)
        );

        // --- bands ----------------------------------------------------------
        $y          = $y0;
        $aisleIndex = 0;
        $rowIndex   = 0;

        foreach ($bands as $band) {
            if ($band['type'] === 'aisle') {
                $body[] = self::renderAisle($x0, $y, $band['height'], $aisleIndex, $entry, $aisleIndex === 0);
                $aisleIndex++;
            } else {
                $body[] = self::renderRow($band['row'], $x0, $y, $band['height'], $rowIndex, $sizeLabels);
                $rowIndex++;
            }
            $y += $band['height'];
        }

        // --- orphan annex (validator fixtures only) --------------------------
        if ($orphans !== []) {
            $body[] = self::renderOrphanAnnex($orphans, $x0, $y0 + $interiorHeight + 24.0);
        }

        return self::assemble($svgWidth, $svgHeight, self::esc($floorName), $body);
    }

    /** @param array<int, string> $body */
    private static function assemble(float $w, float $h, string $title, array $body): string
    {
        $viewBox  = '0 0 ' . self::n($w) . ' ' . self::n($h);
        $contents = implode("\n\n", $body);

        // No width/height attributes: the panel scales the map to its container.
        return <<<SVG
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
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
            .unit-size  { font-size: 8px; fill: #444; text-anchor: middle; pointer-events: none; }
            .aisle-label { font-size: 10px; fill: #555; text-anchor: middle; letter-spacing: 1px; }
            .building-outline { fill: none; stroke: #000; stroke-width: 2.5; }
            .core-outline { fill: #ffffff; stroke: #000; stroke-width: 2; }
            .core-label { font-size: 9px; font-weight: bold; fill: #000; text-anchor: middle; }
            .annex-outline { fill: none; stroke: #999; stroke-width: 1.5; stroke-dasharray: 6 4; }
          </style>

        {$contents}
        </svg>
        SVG;
    }

    // ------------------------------------------------------------------ input

    /** @return array<int, array{unit_number: string, w: float, d: float, label: string}> */
    private static function prepare(array $units): array
    {
        $out = [];

        foreach ($units as $u) {
            $w = max(1.2, (float) ($u['width_m'] ?? 3.0));
            $d = max(1.2, (float) ($u['depth_m'] ?? 3.0));

            $out[] = [
                'unit_number' => (string) $u['unit_number'],
                'w'           => $w,
                'd'           => $d,
                'label'       => sprintf('%.1f×%.1f', $w, $d),
            ];
        }

        // Deepest units to the outside walls, then widest first: keeps each row
        // visually uniform instead of ragged.
        usort($out, static function (array $a, array $b): int {
            return [$b['d'], $b['w'], $a['unit_number']] <=> [$a['d'], $a['w'], $b['unit_number']];
        });

        return $out;
    }

    // ----------------------------------------------------------------- layout

    /** @return array<int, array<int, array>> */
    private static function packRows(array $prepared): array
    {
        $rows    = [];
        $current = [];
        $width   = 0.0;

        foreach ($prepared as $unit) {
            $w = $unit['w'] * self::SCALE;

            if ($current !== [] && ($width + $w) > self::CONTENT_WIDTH) {
                $rows[]  = self::justify($current, $width);
                $current = [];
                $width   = 0.0;
            }

            $current[] = $unit;
            $width    += $w;
        }

        if ($current !== []) {
            $rows[] = self::justify($current, $width, count($rows) > 0);
        }

        return $rows;
    }

    /**
     * Stretch a row's units so it sits flush against both walls. The last row
     * is left ragged (and left-aligned) when it is short — a real building has
     * a short run, not twelve absurdly wide units.
     */
    private static function justify(array $row, float $width, bool $allowRagged = false): array
    {
        $slack = self::CONTENT_WIDTH - $width;
        $count = count($row);

        $ragged = $allowRagged && $slack > (self::CONTENT_WIDTH * 0.28);

        foreach ($row as $i => $unit) {
            $px = $unit['w'] * self::SCALE;

            if (! $ragged) {
                $px += $slack / $count;
            }

            $row[$i]['px'] = $px;
        }

        return $row;
    }

    /** @return array<int, array{type: string, height: float, row?: array}> */
    private static function layoutBands(array $rows): array
    {
        $count = count($rows);

        if ($count === 0) {
            return [['type' => 'aisle', 'height' => self::AISLE]];
        }

        $depth = static function (array $row): float {
            $max = 0.0;
            foreach ($row as $unit) {
                $max = max($max, $unit['d'] * self::SCALE);
            }

            return max(self::MIN_ROW_DEPTH, $max);
        };

        $bands = [];

        // Perimeter row against the top wall.
        $bands[] = ['type' => 'row', 'height' => $depth($rows[0]), 'row' => $rows[0]];

        $last     = $count - 1;
        $interior = [];

        for ($i = 1; $i < $last; $i++) {
            $interior[] = $rows[$i];
        }

        // Interior runs are double-loaded: two rows back to back, aisle between blocks.
        $pairIndex = 0;

        foreach ($interior as $row) {
            if ($pairIndex % 2 === 0) {
                $bands[] = ['type' => 'aisle', 'height' => self::AISLE];
            }

            $bands[] = ['type' => 'row', 'height' => $depth($row), 'row' => $row];
            $pairIndex++;
        }

        if ($last > 0) {
            $bands[] = ['type' => 'aisle', 'height' => self::AISLE];
            $bands[] = ['type' => 'row', 'height' => $depth($rows[$last]), 'row' => $rows[$last]];
        }

        return $bands;
    }

    // ---------------------------------------------------------------- drawing

    private static function renderRow(array $row, float $x0, float $y, float $height, int $rowIndex, bool $sizeLabels): string
    {
        $parts = [sprintf('  <g id="row-%d">', $rowIndex + 1)];
        $x     = $x0;

        foreach ($row as $unit) {
            $w  = $unit['px'];
            $cx = $x + ($w / 2);
            $cy = $y + ($height / 2);

            $number = self::esc($unit['unit_number']);
            $rotate = $w < 46.0;

            $inner = [sprintf(
                '      <rect class="unit" x="%s" y="%s" width="%s" height="%s" />',
                self::n($x), self::n($y), self::n($w), self::n($height)
            )];

            if ($rotate) {
                $inner[] = sprintf(
                    '      <text class="unit-label" x="%s" y="%s" transform="rotate(-90,%s,%s)">%s</text>',
                    self::n($cx), self::n($cy + 3), self::n($cx), self::n($cy + 3), $number
                );
            } else {
                $offset = ($sizeLabels && $height >= 52.0) ? -3.0 : 3.5;

                $inner[] = sprintf(
                    '      <text class="unit-label" x="%s" y="%s">%s</text>',
                    self::n($cx), self::n($cy + $offset), $number
                );

                if ($sizeLabels && $height >= 52.0) {
                    $inner[] = sprintf(
                        '      <text class="unit-size" x="%s" y="%s">%s m</text>',
                        self::n($cx), self::n($cy + 11), self::esc($unit['label'])
                    );
                }
            }

            $parts[] = sprintf(
                '    <g class="storage-unit" id="%s" data-unit-number="%s" data-size="%s">',
                $number, $number, self::esc($unit['label'])
            );
            $parts[] = implode("\n", $inner);
            $parts[] = '    </g>';

            $x += $w;
        }

        $parts[] = '  </g>';

        return implode("\n", $parts);
    }

    private static function renderAisle(float $x0, float $y, float $height, int $index, bool $entry, bool $isFirst): string
    {
        $letter = chr(65 + ($index % 26));
        $cy     = $y + ($height / 2) + 4;

        $parts = [sprintf(
            '  <text class="aisle-label" x="%s" y="%s">AISLE %s</text>',
            self::n($x0 + (self::CONTENT_WIDTH / 2)), self::n($cy), $letter
        )];

        if ($isFirst) {
            $parts[] = $entry
                ? self::renderEntrance($x0, $y, $height)
                : self::renderCore($x0, $y, $height);
        }

        return implode("\n", $parts);
    }

    private static function renderEntrance(float $x0, float $y, float $height): string
    {
        $w = self::CORE_WIDTH;
        $x = $x0 - $w;

        return implode("\n", [
            sprintf('  <g id="entrance">'),
            sprintf('    <rect class="core-outline" x="%s" y="%s" width="%s" height="%s" />', self::n($x), self::n($y + 6), self::n($w), self::n($height - 12)),
            sprintf('    <circle cx="%s" cy="%s" r="3" fill="none" stroke="#000" stroke-width="1" />', self::n($x + 26), self::n($y + ($height / 2))),
            sprintf('    <circle cx="%s" cy="%s" r="3" fill="none" stroke="#000" stroke-width="1" />', self::n($x + 40), self::n($y + ($height / 2))),
            sprintf('    <text class="core-label" x="%s" y="%s">ENTRANCE</text>', self::n($x + ($w / 2)), self::n($y + $height - 18)),
            '  </g>',
        ]);
    }

    private static function renderCore(float $x0, float $y, float $height): string
    {
        $w = self::CORE_WIDTH;
        $x = $x0 - $w;

        return implode("\n", [
            '  <g id="lift-core">',
            sprintf('    <rect class="core-outline" x="%s" y="%s" width="%s" height="%s" />', self::n($x), self::n($y + 6), self::n($w), self::n($height - 12)),
            sprintf('    <line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#000" stroke-width="1" />', self::n($x + 10), self::n($y + 16), self::n($x + $w - 10), self::n($y + $height - 26)),
            sprintf('    <text class="core-label" x="%s" y="%s">LIFT / STAIRS</text>', self::n($x + ($w / 2)), self::n($y + $height - 16)),
            '  </g>',
        ]);
    }

    /** @param array<int, string> $orphans */
    private static function renderOrphanAnnex(array $orphans, float $x0, float $y): string
    {
        $parts = [
            '  <g id="annex">',
            sprintf('    <rect class="annex-outline" x="%s" y="%s" width="%s" height="88" />', self::n($x0), self::n($y), self::n(self::CONTENT_WIDTH)),
            sprintf('    <text class="aisle-label" x="%s" y="%s" text-anchor="start">ANNEX (unsurveyed)</text>', self::n($x0 + 10), self::n($y + 18)),
        ];

        $x = $x0 + 16;

        foreach ($orphans as $number) {
            $number = self::esc($number);

            $parts[] = sprintf('    <g class="storage-unit" id="%s" data-unit-number="%s" data-size="unknown">', $number, $number);
            $parts[] = sprintf('      <rect class="unit" x="%s" y="%s" width="96" height="48" />', self::n($x), self::n($y + 28));
            $parts[] = sprintf('      <text class="unit-label" x="%s" y="%s">%s</text>', self::n($x + 48), self::n($y + 56), $number);
            $parts[] = '    </g>';

            $x += 106;
        }

        $parts[] = '  </g>';

        return implode("\n", $parts);
    }

    // ---------------------------------------------------------------- helpers

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
