<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * Shared "blank submitted field = unchanged" rule for credential updates —
 * a secret input left blank on save must never wipe a stored credential.
 * See 09-conventions-and-invariants.md #26.
 */
final class CredentialField
{
    public static function normalize(?string $submitted): string
    {
        return trim((string) $submitted);
    }

    public static function isBlank(?string $submitted): bool
    {
        return self::normalize($submitted) === '';
    }
}
