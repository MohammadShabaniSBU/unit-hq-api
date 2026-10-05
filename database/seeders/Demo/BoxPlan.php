<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Ground-floor box plan. Numbers, classes and dimensions are read from the
 * stored SVG so the seeder and the floor map stay the same list.
 */
final class BoxPlan
{
    /** @var list<array{unit_number: string, class_code: string, area: float, width_m: float, depth_m: float}>|null */
    private static ?array $units = null;

    /**
     * @return list<array{unit_number: string, class_code: string, area: float, width_m: float, depth_m: float}>
     */
    public static function units(): array
    {
        if (self::$units !== null) {
            return self::$units;
        }

        $svg = self::svg();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Box plan SVG could not be parsed.');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[@data-unit-number]');
        if ($nodes === false) {
            throw new RuntimeException('Box plan SVG has no units.');
        }

        $units = [];
        foreach ($nodes as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $number = trim($element->getAttribute('data-unit-number'));
            $classCode = trim($element->getAttribute('data-class'));
            $area = (float) $element->getAttribute('data-size');
            $rect = self::rect($element);

            if ($number === '' || $classCode === '' || $area <= 0.0 || $rect === null) {
                throw new RuntimeException("Box plan shape {$number} is incomplete.");
            }

            [$width, $depth] = self::metres($area, $rect);
            $units[] = [
                'unit_number' => $number,
                'class_code' => $classCode,
                'area' => $area,
                'width_m' => $width,
                'depth_m' => $depth,
            ];
        }

        if (count($units) !== 168) {
            throw new RuntimeException('Box plan must contain 168 units.');
        }

        self::$units = $units;

        return $units;
    }

    public static function svg(): string
    {
        $svg = file_get_contents(__DIR__.'/maps/box-plan.svg');
        if ($svg === false || trim($svg) === '') {
            throw new RuntimeException('Box plan SVG is missing.');
        }

        return $svg;
    }

    /** @return list<string> */
    public static function unitNumbers(): array
    {
        return array_column(self::units(), 'unit_number');
    }

    private static function rect(DOMElement $group): ?DOMElement
    {
        foreach ($group->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'rect') {
                return $child;
            }
        }

        return null;
    }

    /**
     * Rect aspect ratio, scaled so width × depth equals the box area.
     *
     * @return array{0: float, 1: float}
     */
    private static function metres(float $area, DOMElement $rect): array
    {
        $pxWidth = (float) $rect->getAttribute('width');
        $pxHeight = (float) $rect->getAttribute('height');
        if ($pxWidth <= 0.0 || $pxHeight <= 0.0) {
            throw new RuntimeException('Box plan rect has no size.');
        }

        $width = round(sqrt($area * ($pxWidth / $pxHeight)), 2);
        if ($width <= 0.0) {
            throw new RuntimeException('Box plan width rounded to zero.');
        }

        return [$width, round($area / $width, 2)];
    }
}
