<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Facility;

use App\Support\Facility\FloorPlanGenerator;
use App\Support\Facility\SvgSanitizer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FloorPlanGeneratorTest extends TestCase
{
    #[DataProvider('unitCountProvider')]
    public function test_renders_well_formed_svg(int $count): void
    {
        $svg = FloorPlanGenerator::render('Ground floor', $this->units($count));
        $document = $this->loadSvg($svg);

        $root = $document->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('svg', $root->localName ?? $root->nodeName);
        $this->assertTrue($root->hasAttribute('viewBox'));
        $this->assertFalse($root->hasAttribute('width'));
        $this->assertFalse($root->hasAttribute('height'));
    }

    public static function unitCountProvider(): array
    {
        return [
            'one' => [1],
            'two' => [2],
            'forty_seven' => [47],
            'four_hundred' => [400],
        ];
    }

    public function test_every_unit_appears_exactly_once(): void
    {
        $units = $this->units(47);
        $expected = array_column($units, 'unit_number');
        sort($expected);

        $shapes = $this->storageUnits(FloorPlanGenerator::render('Ground floor', $units));
        $actual = array_map(
            static fn (DOMElement $el): string => $el->getAttribute('data-unit-number'),
            $shapes
        );
        sort($actual);

        $this->assertCount(count($expected), $shapes);
        $this->assertSame($expected, $actual);
    }

    public function test_id_and_data_attribute_agree(): void
    {
        foreach ($this->storageUnits(FloorPlanGenerator::render('Ground floor', $this->units(20))) as $shape) {
            $this->assertSame(
                $shape->getAttribute('data-unit-number'),
                $shape->getAttribute('id')
            );
        }
    }

    public function test_emits_no_status_attribute(): void
    {
        $document = $this->loadSvg(FloorPlanGenerator::render('Ground floor', $this->units(20)));
        $nodes = (new DOMXPath($document))->query('//*[@data-status]');

        $this->assertNotFalse($nodes);
        $this->assertSame(0, $nodes->length);
    }

    public function test_output_is_deterministic(): void
    {
        $units = $this->units(30);

        $this->assertSame(
            FloorPlanGenerator::render('Ground floor', $units),
            FloorPlanGenerator::render('Ground floor', $units)
        );
    }

    public function test_input_order_does_not_matter(): void
    {
        $units = $this->units(30);
        $shuffled = $units;
        // Fixed permutation — avoid RNG so the test itself is deterministic.
        $shuffled = array_reverse($shuffled);
        $mid = intdiv(count($shuffled), 2);
        $shuffled = array_merge(array_slice($shuffled, $mid), array_slice($shuffled, 0, $mid));

        $this->assertSame(
            FloorPlanGenerator::render('Ground floor', $units),
            FloorPlanGenerator::render('Ground floor', $shuffled)
        );
    }

    public function test_no_overlapping_rects(): void
    {
        $rects = $this->unitRects(FloorPlanGenerator::render('Ground floor', $this->units(47)));
        $count = count($rects);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $this->assertFalse(
                    $this->rectsOverlap($rects[$i], $rects[$j]),
                    sprintf('Rects %d and %d overlap', $i, $j)
                );
            }
        }
    }

    public function test_all_shapes_inside_viewbox(): void
    {
        $svg = FloorPlanGenerator::render('Ground floor', $this->units(47));
        $document = $this->loadSvg($svg);
        $viewBox = $this->parseViewBox($document->documentElement->getAttribute('viewBox'));

        foreach ($this->unitRects($svg) as $rect) {
            $this->assertGreaterThanOrEqual($viewBox['minX'], $rect['x']);
            $this->assertGreaterThanOrEqual($viewBox['minY'], $rect['y']);
            $this->assertLessThanOrEqual($viewBox['maxX'], $rect['x'] + $rect['w']);
            $this->assertLessThanOrEqual($viewBox['maxY'], $rect['y'] + $rect['h']);
        }
    }

    public function test_survives_sanitizer_round_trip(): void
    {
        $units = $this->units(20);
        $expected = array_column($units, 'unit_number');
        sort($expected);

        $clean = SvgSanitizer::sanitize(FloorPlanGenerator::render('Ground floor', $units));
        $shapes = $this->storageUnits($clean);
        $actual = array_map(
            static fn (DOMElement $el): string => $el->getAttribute('data-unit-number'),
            $shapes
        );
        sort($actual);

        $this->assertCount(count($expected), $shapes);
        $this->assertSame($expected, $actual);
    }

    public function test_escapes_unit_numbers(): void
    {
        $unitNumber = 'A&B<1>';
        $svg = FloorPlanGenerator::render('Ground floor', [[
            'unit_number' => $unitNumber,
            'width_m' => 3.0,
            'depth_m' => 3.0,
        ]]);

        $this->assertStringContainsString('A&amp;B&lt;1&gt;', $svg);
        $this->assertStringNotContainsString('id="A&B<1>"', $svg);

        $shapes = $this->storageUnits($svg);
        $this->assertCount(1, $shapes);
        $this->assertSame($unitNumber, $shapes[0]->getAttribute('data-unit-number'));
        $this->assertSame($unitNumber, $shapes[0]->getAttribute('id'));
    }

    public function test_handles_single_unit(): void
    {
        $svg = FloorPlanGenerator::render('Ground floor', $this->units(1));
        $shapes = $this->storageUnits($svg);

        $this->assertCount(1, $shapes);
        $this->assertNotEmpty($this->unitRects($svg));
    }

    public function test_handles_four_hundred_units(): void
    {
        $units = $this->units(400);
        $started = hrtime(true);
        $svg = FloorPlanGenerator::render('Upper floor', $units, ['entry' => false]);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertLessThan(250, $elapsedMs, sprintf('render took %.1f ms', $elapsedMs));
        $this->assertCount(400, $this->storageUnits($svg));
    }

    /**
     * @return array<int, array{unit_number: string, width_m: float, depth_m: float}>
     */
    private function units(int $count): array
    {
        $sizes = [
            [1.5, 1.5],
            [2.0, 2.0],
            [2.5, 2.5],
            [3.0, 3.0],
            [3.2, 3.5],
            [4.0, 3.0],
            [5.0, 4.0],
        ];

        $out = [];

        for ($i = 1; $i <= $count; $i++) {
            [$w, $d] = $sizes[($i - 1) % count($sizes)];
            $out[] = [
                'unit_number' => sprintf('MAD-01-SS%d-%02d', (($i - 1) % 10) + 1, $i),
                'width_m' => $w,
                'depth_m' => $d,
            ];
        }

        return $out;
    }

    private function loadSvg(string $svg): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'SVG failed to parse as XML');

        return $document;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function storageUnits(string $svg): array
    {
        $document = $this->loadSvg($svg);
        $nodes = (new DOMXPath($document))->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " storage-unit ")]'
        );

        $this->assertNotFalse($nodes);

        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{x: float, y: float, w: float, h: float}>
     */
    private function unitRects(string $svg): array
    {
        $rects = [];

        foreach ($this->storageUnits($svg) as $shape) {
            $rect = null;
            foreach ($shape->childNodes as $child) {
                if ($child instanceof DOMElement && $child->getAttribute('class') === 'unit') {
                    $rect = $child;
                    break;
                }
            }

            $this->assertNotNull($rect, 'storage-unit missing .unit rect');

            $rects[] = [
                'x' => (float) $rect->getAttribute('x'),
                'y' => (float) $rect->getAttribute('y'),
                'w' => (float) $rect->getAttribute('width'),
                'h' => (float) $rect->getAttribute('height'),
            ];
        }

        return $rects;
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $a
     * @param  array{x: float, y: float, w: float, h: float}  $b
     */
    private function rectsOverlap(array $a, array $b): bool
    {
        $eps = 0.001;

        return $a['x'] + $a['w'] > $b['x'] + $eps
            && $b['x'] + $b['w'] > $a['x'] + $eps
            && $a['y'] + $a['h'] > $b['y'] + $eps
            && $b['y'] + $b['h'] > $a['y'] + $eps;
    }

    /**
     * @return array{minX: float, minY: float, maxX: float, maxY: float}
     */
    private function parseViewBox(string $viewBox): array
    {
        $parts = preg_split('/\s+/', trim($viewBox)) ?: [];
        $this->assertCount(4, $parts);

        $minX = (float) $parts[0];
        $minY = (float) $parts[1];

        return [
            'minX' => $minX,
            'minY' => $minY,
            'maxX' => $minX + (float) $parts[2],
            'maxY' => $minY + (float) $parts[3],
        ];
    }
}
