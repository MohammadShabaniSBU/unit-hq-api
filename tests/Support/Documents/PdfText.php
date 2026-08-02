<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

/**
 * Honest PDF fixtures: normalized extracted text + page count.
 * Dompdf bytes are not byte-stable across environments.
 */
final class PdfText
{
    public static function pageCount(string $pdfBytes): int
    {
        if (! str_starts_with($pdfBytes, '%PDF')) {
            return 0;
        }

        // Dompdf emits /Type /Page for each page (not /Pages).
        preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes, $matches);

        return max(1, count($matches[0]));
    }

    public static function normalizeHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/', "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Extract readable strings from PDF content streams (good enough for goldens).
     */
    public static function extract(string $pdfBytes): string
    {
        $chunks = [];
        if (preg_match_all('/\((.*?)\)\s*Tj/s', $pdfBytes, $matches)) {
            foreach ($matches[1] as $chunk) {
                $chunks[] = stripcslashes($chunk);
            }
        }
        // Also catch TJ arrays loosely.
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $pdfBytes, $tjMatches)) {
            foreach ($tjMatches[1] as $array) {
                if (preg_match_all('/\((.*?)\)/', $array, $inner)) {
                    foreach ($inner[1] as $chunk) {
                        $chunks[] = stripcslashes($chunk);
                    }
                }
            }
        }

        $text = implode(' ', $chunks);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function assertContainsAll(string $haystack, array $needles): void
    {
        foreach ($needles as $needle) {
            if (! str_contains($haystack, $needle)) {
                throw new \PHPUnit\Framework\AssertionFailedError(
                    "Failed asserting that PDF/HTML text contains [{$needle}].\nGot:\n{$haystack}"
                );
            }
        }
    }
}
