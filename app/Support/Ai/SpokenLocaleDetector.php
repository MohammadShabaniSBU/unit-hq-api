<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Best-effort spoken-language guess from a short utterance. Returns null
 * on empty, unmarked, or tied input so a mixed or tiny phrase does not
 * flip agent_conversations.locale.
 */
final class SpokenLocaleDetector
{
    public static function detect(string $text): ?string
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return null;
        }

        /** @var array<string, list<string>> $markers */
        $markers = config('ai-handoff.spoken_locale_markers', []);
        $scores = [];
        foreach (['en', 'es', 'fr'] as $locale) {
            $set = [];
            foreach ($markers[$locale] ?? [] as $marker) {
                $key = self::normalize((string) $marker);
                if ($key !== '') {
                    $set[$key] = true;
                }
            }

            $score = 0;
            foreach ($tokens as $token) {
                if (isset($set[$token]) || isset($set[self::stem($token)])) {
                    $score++;
                }
            }
            $scores[$locale] = $score;
        }

        $topScore = max($scores);
        if ($topScore === 0) {
            return null;
        }

        $winners = array_keys(array_filter($scores, fn (int $score): bool => $score === $topScore));
        if (count($winners) !== 1) {
            return null;
        }

        return $winners[0];
    }

    private static function normalize(string $text): string
    {
        $folded = mb_strtolower($text, 'UTF-8');
        $stripped = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($folded, \Normalizer::FORM_D) ?? $folded) ?? $folded;
        $letters = preg_replace("/[^\p{L}\p{N}\s']+/u", ' ', $stripped) ?? $stripped;

        return trim((string) preg_replace('/\s+/u', ' ', $letters));
    }

    private static function stem(string $token): string
    {
        if (strlen($token) > 3 && str_ends_with($token, 's')) {
            return substr($token, 0, -1);
        }

        return $token;
    }
}
