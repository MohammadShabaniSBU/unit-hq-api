<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\Site;
use App\Support\Ai\Tools\FactBag;
use App\Support\Time\SiteClock;

final class DraftTokenExtractor
{
    /** @var list<array{0: int, 1: int}> */
    private array $occupied = [];

    /**
     * @return list<DraftToken>
     */
    public function extract(string $text, ?Site $site = null): array
    {
        $this->occupied = [];
        $tokens = [];

        $this->collectCurrency($text, $tokens);
        $this->collectPercents($text, $tokens);
        $this->collectIsoDates($text, $tokens);
        $this->collectSlashDates($text, $tokens);
        $this->collectRelativeDates($text, $tokens, $site);
        $this->collectUnitIds($text, $tokens);
        $this->collectBareDecimals($text, $tokens);
        $this->collectIntegers($text, $tokens);

        return $tokens;
    }

    /**
     * @return list<string>
     */
    public function extractPercents(string $text): array
    {
        $tokens = [];
        $this->occupied = [];
        $this->collectPercents($text, $tokens);

        return array_values(array_unique(array_map(
            fn (DraftToken $token): string => $token->normalized,
            $tokens,
        )));
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectCurrency(string $text, array &$tokens): void
    {
        $pattern = '/(?:€|£|\$)\s*\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?'
            .'|\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?\s*(?:€|£|\$|EUR|GBP|USD)'
            .'|(?:EUR|GBP|USD)\s*\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?/iu';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $currency = $this->currencyFrom($raw);
            $amount = FactBag::tryNormalizeAmount($raw);
            if ($amount === null) {
                continue;
            }

            $tokens[] = new DraftToken(DraftToken::Money, $raw, $amount, $currency);
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectPercents(string $text, array &$tokens): void
    {
        if (preg_match_all('/\d+(?:[.,]\d+)?\s*%/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $tokens[] = new DraftToken(DraftToken::Percent, $raw, FactBag::normalizePercent($raw));
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectIsoDates(string $text, array &$tokens): void
    {
        if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $tokens[] = new DraftToken(DraftToken::Date, $raw, $raw);
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectSlashDates(string $text, array &$tokens): void
    {
        if (preg_match_all('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}\b/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $normalized = $this->normalizeEuropeanDate($raw) ?? $raw;
            $tokens[] = new DraftToken(DraftToken::Date, $raw, $normalized);
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectRelativeDates(string $text, array &$tokens, ?Site $site): void
    {
        $pattern = '/\b(today|tomorrow|yesterday|hoy|mañana|manana|ayer|aujourd\'hui|demain|hier)\b/iu';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        $deltas = [
            'today' => 0,
            'hoy' => 0,
            "aujourd'hui" => 0,
            'tomorrow' => 1,
            'mañana' => 1,
            'manana' => 1,
            'demain' => 1,
            'yesterday' => -1,
            'ayer' => -1,
            'hier' => -1,
        ];

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $folded = KeywordMatcher::fold($raw);
            $delta = $deltas[$folded] ?? 0;
            $normalized = $site !== null
                ? SiteClock::today($site)->addDays($delta)->toDateString()
                : $folded;

            $tokens[] = new DraftToken(DraftToken::Date, $raw, $normalized);
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectUnitIds(string $text, array &$tokens): void
    {
        if (preg_match_all('/\b[A-Za-z]{1,3}-\d{1,4}\b|\b[A-Za-z]\d{2,4}\b/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $tokens[] = new DraftToken(DraftToken::Identifier, $raw, strtoupper($raw));
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectBareDecimals(string $text, array &$tokens): void
    {
        if (preg_match_all('/\b\d+[.,]\d{1,2}\b/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            if (! $this->claim((int) $offset, strlen($raw))) {
                continue;
            }

            $amount = FactBag::tryNormalizeAmount($raw);
            if ($amount === null) {
                continue;
            }

            $tokens[] = new DraftToken(DraftToken::Number, $raw, $amount);
        }
    }

    /**
     * @param  list<DraftToken>  $tokens
     */
    private function collectIntegers(string $text, array &$tokens): void
    {
        if (preg_match_all('/\b\d+\b/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as [$raw, $offset]) {
            $start = (int) $offset;
            $len = strlen($raw);
            if (! $this->claim($start, $len)) {
                continue;
            }

            $value = (int) $raw;
            $adjacent = $this->adjacentToCurrencyOrArea($text, $start, $start + $len);
            if ($value >= 1 && $value <= 12 && ! $adjacent) {
                continue;
            }

            $tokens[] = new DraftToken(DraftToken::Number, $raw, (string) $value);
        }
    }

    private function adjacentToCurrencyOrArea(string $text, int $start, int $end): bool
    {
        $before = substr($text, max(0, $start - 12), min(12, $start));
        $after = substr($text, $end, 12);

        if (preg_match('/[€£\$]\s*$/u', $before) === 1) {
            return true;
        }
        if (preg_match('/^\s*(?:€|£|\$|EUR|GBP|USD)/u', $after) === 1) {
            return true;
        }
        if (preg_match('/(m²|m2|sqm)\s*$/iu', $before) === 1) {
            return true;
        }
        if (preg_match('/^\s*(m²|m2|sqm)/iu', $after) === 1) {
            return true;
        }

        return false;
    }

    private function currencyFrom(string $raw): string
    {
        if (str_contains($raw, '€') || preg_match('/EUR/i', $raw) === 1) {
            return 'EUR';
        }
        if (str_contains($raw, '£') || preg_match('/GBP/i', $raw) === 1) {
            return 'GBP';
        }
        if (str_contains($raw, '$') || preg_match('/USD/i', $raw) === 1) {
            return 'USD';
        }

        return 'EUR';
    }

    private function normalizeEuropeanDate(string $raw): ?string
    {
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $raw, $m) !== 1) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function claim(int $start, int $len): bool
    {
        $end = $start + $len;
        foreach ($this->occupied as [$s, $e]) {
            if ($start < $e && $end > $s) {
                return false;
            }
        }

        $this->occupied[] = [$start, $end];

        return true;
    }
}
