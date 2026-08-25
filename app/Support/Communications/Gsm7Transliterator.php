<?php

declare(strict_types=1);

namespace App\Support\Communications;

/**
 * Map agent SMS drafts onto the GSM-7 alphabet before segment counting.
 * Never applied to email, WhatsApp, or operator-authored templates.
 */
final class Gsm7Transliterator
{
    private const MAP = [
        '²' => '2',
        '³' => '3',
        '€' => 'EUR',
        '—' => '-',
        '–' => '-',
        '“' => '"',
        '”' => '"',
        '‘' => "'",
        '’' => "'",
        '…' => '...',
        "\u{00A0}" => ' ',
        "\u{202F}" => ' ',
    ];

    /**
     * @return array{body: string, changed: bool}
     */
    public static function apply(string $body): array
    {
        $out = strtr($body, self::MAP);
        $out = preg_replace('/EUR(?=\d)/u', 'EUR ', $out) ?? $out;
        $out = preg_replace('/(?<=\d)EUR/u', ' EUR', $out) ?? $out;

        return [
            'body' => $out,
            'changed' => $out !== $body,
        ];
    }
}
