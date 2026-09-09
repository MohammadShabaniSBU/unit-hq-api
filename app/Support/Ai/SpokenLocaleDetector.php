<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Heuristic language guess from a caller utterance. Returns null when the
 * text is too short or the scores are too close, so a greeting like "ok"
 * never flips the conversation locale.
 */
final class SpokenLocaleDetector
{
    private const MIN_WORDS = 4;

    private const MIN_HITS = 2;

    private const MARGIN = 2;

    /**
     * @return 'en'|'es'|'fr'|null
     */
    public static function detect(string $utterance): ?string
    {
        $words = self::words($utterance);
        if (count($words) < self::MIN_WORDS) {
            return null;
        }

        /** @var array<string, list<string>> $markers */
        $markers = config('ai-handoff.spoken_locale_markers', []);
        $scores = [];
        foreach (['en', 'es', 'fr'] as $locale) {
            $set = [];
            foreach ($markers[$locale] ?? [] as $marker) {
                $normalized = self::normalize($marker);
                if ($normalized !== '') {
                    $set[$normalized] = true;
                }
            }

            $hits = 0;
            foreach ($words as $word) {
                if (isset($set[$word])) {
                    $hits++;
                }
            }
            $scores[$locale] = $hits;
        }

        arsort($scores);
        $ranked = array_keys($scores);
        $winner = $ranked[0];
        $winnerScore = $scores[$winner];
        $runnerUp = $scores[$ranked[1] ?? ''] ?? 0;

        if ($winnerScore < self::MIN_HITS) {
            return null;
        }
        if ($winnerScore < $runnerUp + self::MARGIN) {
            return null;
        }

        return $winner;
    }

    /**
     * @return list<string>
     */
    private static function words(string $utterance): array
    {
        preg_match_all('/[a-z]+/u', self::normalize($utterance), $matches);

        return $matches[0];
    }

    private static function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));

        return strtr($lower, [
            'á' => 'a',
            'à' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
            'ç' => 'c',
        ]);
    }
}
