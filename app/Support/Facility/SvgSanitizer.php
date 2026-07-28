<?php

declare(strict_types=1);

namespace App\Support\Facility;

use DOMDocument;
use DOMElement;
use DOMXPath;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Validation\ValidationException;

/**
 * Strips dangerous content from uploaded SVG floor maps before they are
 * persisted: <script> tags, on* event handlers, <foreignObject>, and any
 * href/xlink:href that doesn't point to an in-document fragment (#id).
 *
 * Only the sanitized output is ever stored — callers must not persist the
 * raw input.
 */
final class SvgSanitizer
{
    public static function sanitize(string $svg): string
    {
        $svg = trim($svg);

        if ($svg === '') {
            return '';
        }

        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);
        $sanitizer->minify(false);

        $clean = $sanitizer->sanitize($svg);

        if ($clean === false || trim($clean) === '') {
            throw ValidationException::withMessages([
                'svg_map' => ['The SVG map could not be parsed. Please upload a valid SVG document.'],
            ]);
        }

        return self::stripExternalHrefs($clean);
    }

    /**
     * The base sanitizer allows http(s) hrefs through as "safe" values, but
     * floor maps should never reference external resources — only
     * in-document fragments (e.g. `href="#unit-101"`).
     */
    private static function stripExternalHrefs(string $svg): string
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        $previousErrorSetting = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        if (! $loaded) {
            return $svg;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        foreach (['href', 'xlink:href'] as $attribute) {
            $nodes = $xpath->query('//*[@'.$attribute.']');

            if ($nodes === false) {
                continue;
            }

            foreach (iterator_to_array($nodes) as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }

                $value = $element->getAttribute($attribute);

                if ($value !== '' && ! str_starts_with($value, '#')) {
                    $element->removeAttribute($attribute);
                }
            }
        }

        return $document->saveXML($document->documentElement) ?: $svg;
    }
}
