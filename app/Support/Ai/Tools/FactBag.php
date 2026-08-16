<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

/**
 * Tokens a turn is licensed to emit. Grounding (S22-03) diffs the draft against this.
 */
final class FactBag
{
    /** @var array<string, true> */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $moneyAmounts = [];

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

        return $this;
    }

    public function identifier(string $identifier): self
    {
        $this->addToken($identifier);

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

    public function merge(self $other): self
    {
        foreach ($other->tokens as $token => $_) {
            $this->tokens[$token] = true;
        }
        foreach ($other->moneyAmounts as $amount => $_) {
            $this->moneyAmounts[$amount] = true;
        }

        return $this;
    }

    public function contains(string $candidate): bool
    {
        if (isset($this->tokens[$candidate]) || isset($this->tokens[mb_strtolower($candidate)])) {
            return true;
        }

        $amount = self::tryNormalizeAmount($candidate);
        if ($amount !== null && isset($this->moneyAmounts[$amount])) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->tokens);
    }

    public static function fromCustomerMessage(string $input): self
    {
        $bag = new self;

        if (preg_match_all('/€\s*(\d+[.,]\d{1,2})|(\d+[.,]\d{1,2})\s*€|(\d+[.,]\d{1,2})\s*EUR|EUR\s*(\d+[.,]\d{1,2})/iu', $input, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $raw = $match[1] ?? $match[2] ?? $match[3] ?? $match[4] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $bag->money($raw, 'EUR');
                }
            }
        }

        if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $input, $dates) !== false) {
            foreach ($dates[0] as $date) {
                $bag->date($date);
            }
        }

        if (preg_match_all('/\b[A-Z]-\d+\b/', $input, $ids) !== false) {
            foreach ($ids[0] as $id) {
                $bag->identifier($id);
            }
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
