<?php

declare(strict_types=1);

namespace App\Support\Communications;

use InvalidArgumentException;

/**
 * Pixel geometry for the 600px email card.
 * Column widths are integers and sum to the inner width (container minus section padding).
 */
final class EmailLayout
{
    public const CONTAINER_WIDTH = 600;

    /** @var array<string, int> */
    public const PADDING_PX = [
        'none' => 0,
        'sm' => 12,
        'md' => 24,
        'lg' => 40,
    ];

    /**
     * @return list<int>
     */
    public static function columnWidths(string $preset, string $padding): array
    {
        if (! isset(self::PADDING_PX[$padding])) {
            throw new InvalidArgumentException('Unknown email padding token: '.$padding);
        }

        $inner = self::CONTAINER_WIDTH - (2 * self::PADDING_PX[$padding]);

        return match ($preset) {
            '100' => [$inner],
            '50_50' => self::remainderPair(intdiv($inner, 2), $inner),
            '33_67' => self::remainderPair(intdiv($inner * 33, 100), $inner),
            '67_33' => self::remainderPair(intdiv($inner * 67, 100), $inner),
            '33_33_33' => self::thirds($inner),
            default => throw new InvalidArgumentException('Unknown email layout preset: '.$preset),
        };
    }

    /**
     * @return list<int>
     */
    private static function remainderPair(int $first, int $inner): array
    {
        return [$first, $inner - $first];
    }

    /**
     * @return list<int>
     */
    private static function thirds(int $inner): array
    {
        $base = intdiv($inner, 3);

        return [$base, $base, $base + ($inner % 3)];
    }
}
