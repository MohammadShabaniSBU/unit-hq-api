<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\TaxIdType;

/**
 * Stateless tax-ID normalize + validate helpers.
 * No remote VIES calls — checksum/format only for deployed-market IDs.
 */
final class TaxId
{
    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    private const CIF_CONTROL_LETTERS = 'JABCDEFGHI';

    /** Org letters that require a letter control character. */
    private const CIF_LETTER_ONLY = ['K', 'P', 'Q', 'S', 'W'];

    /** Org letters that require a digit control character. */
    private const CIF_DIGIT_ONLY = ['A', 'B', 'E', 'H'];

    public static function normalize(string $value): string
    {
        $normalized = strtoupper(trim($value));

        return preg_replace('/[\s.\-]/', '', $normalized) ?? $normalized;
    }

    public static function validate(string $value, string $type): bool
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return false;
        }

        return match ($type) {
            TaxIdType::Nif->value => self::validateNif($normalized),
            TaxIdType::Nie->value => self::validateNie($normalized),
            TaxIdType::Siren->value => self::validateSiren($normalized),
            TaxIdType::Siret->value => self::validateSiret($normalized),
            TaxIdType::Vat->value => self::validateVat($normalized),
            TaxIdType::UkCrn->value => self::validateUkCrn($normalized),
            TaxIdType::Other->value => self::validateOther($normalized),
            default => false,
        };
    }

    private static function validateNif(string $value): bool
    {
        $body = self::stripEsPrefix($value);

        if (preg_match('/^\d{8}[A-Z]$/', $body) === 1) {
            return self::validateDniLetter($body);
        }

        if (preg_match('/^[ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]$/', $body) === 1) {
            return self::validateCif($body);
        }

        return false;
    }

    private static function validateNie(string $value): bool
    {
        $body = self::stripEsPrefix($value);

        if (preg_match('/^[XYZ]\d{7}[A-Z]$/', $body) !== 1) {
            return false;
        }

        $prefixMap = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $asDni = $prefixMap[$body[0]].substr($body, 1);

        return self::validateDniLetter($asDni);
    }

    private static function validateDniLetter(string $dni): bool
    {
        $number = (int) substr($dni, 0, 8);
        $letter = $dni[8];

        return self::DNI_LETTERS[$number % 23] === $letter;
    }

    private static function validateCif(string $cif): bool
    {
        $org = $cif[0];
        $digits = substr($cif, 1, 7);
        $control = $cif[8];

        $evenSum = 0;
        $oddSum = 0;

        for ($i = 0; $i < 7; $i++) {
            $digit = (int) $digits[$i];

            if (($i % 2) === 0) {
                $doubled = $digit * 2;
                $oddSum += intdiv($doubled, 10) + ($doubled % 10);
            } else {
                $evenSum += $digit;
            }
        }

        $units = ($evenSum + $oddSum) % 10;
        $controlDigit = (10 - $units) % 10;
        $controlLetter = self::CIF_CONTROL_LETTERS[$controlDigit];

        if (in_array($org, self::CIF_LETTER_ONLY, true)) {
            return $control === $controlLetter;
        }

        if (in_array($org, self::CIF_DIGIT_ONLY, true)) {
            return $control === (string) $controlDigit;
        }

        return $control === (string) $controlDigit || $control === $controlLetter;
    }

    private static function validateSiren(string $value): bool
    {
        if (preg_match('/^\d{9}$/', $value) !== 1) {
            return false;
        }

        return self::luhn($value);
    }

    private static function validateSiret(string $value): bool
    {
        if (preg_match('/^\d{14}$/', $value) !== 1) {
            return false;
        }

        return self::luhn($value);
    }

    private static function validateVat(string $value): bool
    {
        return preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $value) === 1;
    }

    private static function validateUkCrn(string $value): bool
    {
        return preg_match('/^([A-Z]{2})?\d{6,8}$/', $value) === 1;
    }

    private static function validateOther(string $value): bool
    {
        return preg_match('/^[A-Z0-9]{2,64}$/', $value) === 1;
    }

    private static function stripEsPrefix(string $value): string
    {
        if (str_starts_with($value, 'ES') && strlen($value) > 2) {
            return substr($value, 2);
        }

        return $value;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alt = ! $alt;
        }

        return ($sum % 10) === 0;
    }
}
