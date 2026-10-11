<?php

declare(strict_types=1);

namespace App\Support\Advertising;

/**
 * Identifier normalisation for ad-platform hashing. Callers hash at send time.
 * Delivery rows store the identifier name, never the value (S32 A7).
 */
final class Hashing
{
    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Digits only. A leading + and separators are dropped. The caller supplies
     * an E.164 number; this method does not add a country code.
     */
    public static function normalisePhoneE164(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function sha256(string $normalised): string
    {
        return hash('sha256', $normalised);
    }
}
