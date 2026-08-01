<?php

declare(strict_types=1);

namespace App\Support\Communications;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Strip scripts, event handlers, javascript: URLs, and external http(s) refs
 * from HTML at write time. Mirrors the site-map SVG discipline for untrusted
 * / stored HTML bodies.
 */
final class HtmlSanitizer
{
    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Wrap fragment so DOMDocument can parse it.
        $wrapped = '<?xml encoding="UTF-8"><div id="__sanitize_root">'.$html.'</div>';
        $loaded = $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return strip_tags($html);
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script|//iframe|//object|//embed|//form') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($xpath->query('//*') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $attrsToRemove = [];
            foreach ($node->attributes ?? [] as $attr) {
                $name = strtolower($attr->name);
                $value = trim($attr->value);

                if (str_starts_with($name, 'on')) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                if (in_array($name, ['href', 'src', 'xlink:href', 'action', 'formaction'], true)) {
                    if (preg_match('/^\s*javascript\s*:/i', $value) === 1) {
                        $attrsToRemove[] = $attr->name;
                        continue;
                    }

                    // Strip absolute external http(s) references (keep cid:/mailto:/data:/#).
                    if (preg_match('/^\s*https?:/i', $value) === 1) {
                        $attrsToRemove[] = $attr->name;
                    }
                }
            }

            foreach ($attrsToRemove as $attrName) {
                $node->removeAttribute($attrName);
            }
        }

        $root = $document->getElementById('__sanitize_root');
        if ($root === null) {
            return strip_tags($html);
        }

        $inner = '';
        foreach ($root->childNodes ?? [] as $child) {
            $inner .= self::exportNode($document, $child);
        }

        return $inner;
    }

    private static function exportNode(DOMDocument $document, DOMNode $node): string
    {
        return $document->saveHTML($node) ?: '';
    }
}
