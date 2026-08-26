<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

final class KeywordMatcher
{
    public static function fold(string $text): string
    {
        $normalized = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text)
            : $text;
        $stripped = preg_replace('/\p{Mn}/u', '', $normalized) ?? $normalized;
        $apostrophe = str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", $stripped);

        return mb_strtolower($apostrophe);
    }

    /**
     * @param  list<string>  $keywords
     */
    public static function firstMatch(string $haystack, array $keywords): ?string
    {
        $folded = self::fold($haystack);

        foreach ($keywords as $keyword) {
            $needle = self::fold($keyword);
            if ($needle === '') {
                continue;
            }

            $quoted = preg_quote($needle, '/');
            if (preg_match('/(?<![\p{L}\p{N}_])'.$quoted.'(?![\p{L}\p{N}_])/u', $folded) === 1) {
                return $keyword;
            }
        }

        return null;
    }
}
