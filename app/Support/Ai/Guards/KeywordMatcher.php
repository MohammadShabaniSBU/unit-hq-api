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

        return mb_strtolower($stripped);
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
