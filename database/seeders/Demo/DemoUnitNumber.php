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
    /**
     * Highest index StageSeeder writes (SS2 / B74 on the box plan).
     * Legacy `{SITE}-{CLASS}-{NN}` numbers only ever ran 01–10.
     */
    private const MAX_INDEX = 74;

    /** @var array<string, string> */
    private const PREFIXES = [
        'SS1.5' => 'A',
        'SS2' => 'B',
        'SS2.5' => 'C',
        'SS3' => 'D',
        'SS5' => 'G',
        'SS6' => 'H',
        'SS7' => 'J',
        'SS8' => 'K',
        'SS9' => 'L',
        'SS10' => 'M',
        'SS11' => 'N',
        'SS12' => 'P',
        'AL10' => 'AL',
        'AL12' => 'AM',
        'AL14' => 'AN',
        'AL16' => 'AP',
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
        foreach (range(1, self::MAX_INDEX) as $n) {
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
