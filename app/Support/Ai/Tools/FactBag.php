<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Site;
use App\Support\Ai\Guards\DraftToken;
use App\Support\Ai\Guards\DraftTokenExtractor;

/**
 * Tokens a turn is licensed to emit. Grounding (S22-03) diffs the draft against this.
 */
final class FactBag
{
    /** @var array<string, true> */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $moneyAmounts = [];

    /** @var array<string, true> */
    private array $percents = [];

    public function money(string $amount, string $currency): self
    {
        $normalized = self::normalizeAmount($amount);
        $this->moneyAmounts[$normalized] = true;
        $comma = str_replace('.', ',', $normalized);
        $currency = strtoupper($currency);

        foreach ([
            $normalized,
            $comma,
            $currency.' '.$normalized,
            $normalized.' '.$currency,
            '€'.$normalized,
            '€'.$comma,
            $normalized.'€',
            $comma.'€',
        ] as $form) {
            $this->addToken($form);
        }

        return $this;
    }

    public function date(string $date): self
    {
        $this->addToken($date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $this->addToken(substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4));
            $this->addToken(substr($date, 8, 2).'-'.substr($date, 5, 2).'-'.substr($date, 0, 4));
        }

        return $this;
    }

    public function identifier(string $identifier): self
    {
        $this->addToken($identifier);
        $this->addToken(strtoupper($identifier));

        return $this;
    }

    public function number(int|float|string $number): self
    {
        $this->addToken((string) $number);
        if (is_numeric($number)) {
            $this->moneyAmounts[self::normalizeAmount((string) $number)] = true;
        }

        return $this;
    }

    public function percent(string $rate): self
    {
        $normalized = self::normalizePercent($rate);
        $this->percents[$normalized] = true;
        $this->addToken($normalized);
        $this->addToken($normalized.'%');
        $this->addToken($normalized.' %');

        return $this;
    }

    public function merge(self $other): self
    {
        foreach ($other->tokens as $token => $_) {
            $this->tokens[$token] = true;
        }
        foreach ($other->moneyAmounts as $amount => $_) {
            $this->moneyAmounts[$amount] = true;
        }
        foreach ($other->percents as $percent => $_) {
            $this->percents[$percent] = true;
        }

        return $this;
    }

    public function contains(string $candidate): bool
    {
        if (isset($this->tokens[$candidate]) || isset($this->tokens[mb_strtolower($candidate)])) {
            return true;
        }

        $upper = strtoupper($candidate);
        if (isset($this->tokens[$upper])) {
            return true;
        }

        $amount = self::tryNormalizeAmount($candidate);
        if ($amount !== null && isset($this->moneyAmounts[$amount])) {
            return true;
        }

        return false;
    }

    public function containsPercent(string $candidate): bool
    {
        return isset($this->percents[self::normalizePercent($candidate)]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map(strval(...), array_keys($this->tokens));
    }

    /**
     * @param  list<string|int>  $keys
     */
    public static function fromKeys(array $keys): self
    {
        $bag = new self;
        foreach ($keys as $key) {
            $key = (string) $key;
            $bag->addToken($key);
            $amount = self::tryNormalizeAmount($key);
            if ($amount !== null) {
                $bag->moneyAmounts[$amount] = true;
            }
            if (str_contains($key, '%')) {
                $bag->percents[self::normalizePercent($key)] = true;
            }
        }

        return $bag;
    }

    public static function fromCustomerMessage(string $input, ?Site $site = null): self
    {
        $bag = new self;
        $extractor = new DraftTokenExtractor;

        foreach ($extractor->extract($input, $site) as $token) {
            match ($token->type) {
                DraftToken::Money => $bag->money($token->normalized, $token->currency ?? 'EUR'),
                DraftToken::Percent => $bag->percent($token->normalized),
                DraftToken::Date => $bag->date($token->normalized),
                DraftToken::Identifier => $bag->identifier($token->raw),
                DraftToken::Number => $bag->number($token->normalized),
                default => null,
            };
        }

        return $bag;
    }

    private function addToken(string $token): void
    {
        $this->tokens[$token] = true;
        $this->tokens[mb_strtolower($token)] = true;
    }

    public static function normalizeAmount(string $amount): string
    {
        $normalized = self::tryNormalizeAmount($amount);

        return $normalized ?? number_format((float) $amount, 2, '.', '');
    }

    public static function normalizePercent(string $raw): string
    {
        $amount = self::tryNormalizeAmount($raw);
        if ($amount === null) {
            $cleaned = preg_replace('/[^\d,.\-]/u', '', $raw) ?? '';

            return $cleaned !== '' ? $cleaned : $raw;
        }

        $trimmed = rtrim(rtrim($amount, '0'), '.');

        return $trimmed !== '' ? $trimmed : '0';
    }

    public static function tryNormalizeAmount(string $raw): ?string
    {
        $cleaned = preg_replace('/[^\d,.\-]/u', '', $raw) ?? '';
        if ($cleaned === '' || $cleaned === '-' || $cleaned === '.' || $cleaned === ',') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $cleaned) === 1) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (preg_match('/^\d+,\d{1,2}$/', $cleaned) === 1) {
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+\.\d{1,2}$/', $cleaned) === 1) {
            $cleaned = str_replace(',', '', $cleaned);
        }

        if (! is_numeric($cleaned)) {
            return null;
        }

        return number_format((float) $cleaned, 2, '.', '');
    }
}
