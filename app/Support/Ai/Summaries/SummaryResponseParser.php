<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries;

/**
 * Parses model output into body + optional highlights.
 * A bad highlights payload never fails the summary — highlights become null.
 */
final class SummaryResponseParser
{
    /**
     * @return array{body: string, highlights: list<array{key: string, label_key: string|null, value: string}>|null}
     */
    public static function parse(string $text): array
    {
        $trimmed = trim($text);
        $decoded = self::decodeJsonObject($trimmed);

        if ($decoded === null) {
            return [
                'body' => $trimmed,
                'highlights' => null,
            ];
        }

        $body = $decoded['body'] ?? null;
        if (! is_string($body) || trim($body) === '') {
            return [
                'body' => $trimmed,
                'highlights' => null,
            ];
        }

        return [
            'body' => trim($body),
            'highlights' => self::normalizeHighlights($decoded['highlights'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonObject(string $text): ?array
    {
        $candidate = $text;
        if (! str_starts_with(ltrim($text), '{')) {
            if (preg_match('/\{.*\}/s', $text, $matches) !== 1) {
                return null;
            }
            $candidate = $matches[0];
        }

        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array{key: string, label_key: string|null, value: string}>|null
     */
    private static function normalizeHighlights(mixed $highlights): ?array
    {
        if (! is_array($highlights)) {
            return null;
        }

        $allowed = config('ai.summaries.highlight_keys', []);
        $allowed = is_array($allowed) ? $allowed : [];

        $out = [];
        foreach ($highlights as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;
            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }
            if ($allowed !== [] && ! in_array($key, $allowed, true)) {
                continue;
            }

            $labelKey = $row['label_key'] ?? null;
            $out[] = [
                'key' => $key,
                'label_key' => is_string($labelKey) && $labelKey !== '' ? $labelKey : null,
                'value' => $value,
            ];
        }

        return $out === [] ? null : $out;
    }
}
