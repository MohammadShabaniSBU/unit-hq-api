<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use RuntimeException;

/**
 * Demo-world unit numbers. Stage seed writes these; the shorten command
 * remaps the older `{SITE}-{CLASS}-{NN}` form onto the same scheme.
 */
final class DemoUnitNumber
{
    /** @var array<string, string> */
    private const PREFIXES = [
        'SS1' => 'G',
        'SS2' => 'H',
        'SS3' => 'J',
        'SS4' => 'K',
        'SS5' => 'L',
        'SS6' => 'M',
        'SS7' => 'N',
        'SS8' => 'P',
        'AL1' => 'AL',
        'AL2' => 'AM',
        'AL3' => 'AN',
        'AL4' => 'AP',
    ];

    public static function format(string $classCode, int $n): string
    {
        $formatted = self::tryFormat($classCode, $n);
        if ($formatted === null) {
            throw new RuntimeException("No demo unit-number prefix for class {$classCode}.");
        }

        return $formatted;
    }

    public static function tryFormat(string $classCode, int $n): ?string
    {
        $prefix = self::PREFIXES[$classCode] ?? null;
        if ($prefix === null) {
            return null;
        }

        return $prefix.$n;
    }

    public static function isCurrentFormat(string $classCode, string $unitNumber): bool
    {
        foreach (range(1, 10) as $n) {
            $candidate = self::tryFormat($classCode, $n);
            if ($candidate !== null && $candidate === $unitNumber) {
                return true;
            }
        }

        return false;
    }

    public static function parseLegacyIndex(string $siteCode, string $classCode, string $unitNumber): ?int
    {
        if ($siteCode === '' || $classCode === '') {
            return null;
        }

        $prefix = $siteCode.'-'.$classCode.'-';
        if (! str_starts_with($unitNumber, $prefix)) {
            return null;
        }

        $suffix = substr($unitNumber, strlen($prefix));
        if ($suffix === '' || ! ctype_digit($suffix)) {
            return null;
        }

        $n = (int) $suffix;
        if ($n < 1 || $n > 10) {
            return null;
        }

        return $n;
    }
}
