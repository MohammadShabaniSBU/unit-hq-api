<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared secret-masking rules for every credential surface (comms provider
 * API keys, per-site Stripe keys): secrets are never returned raw — only
 * "••••••" + the last 4 characters. See 09-conventions-and-invariants.md #26.
 */
final class CredentialMasker
{
    public static function mask(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return '••••••'.substr($secret, -4);
    }

    /**
     * Reads an `encrypted`-cast attribute, converting an unreadable
     * ciphertext (e.g. after an APP_KEY rotation) into null instead of
     * letting a DecryptException bubble into a 500.
     */
    public static function readSafely(Model $model, string $attribute): ?string
    {
        try {
            $value = $model->{$attribute};

            return $value === '' ? null : $value;
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * True when the DB holds ciphertext for this column but it could not be
     * decrypted — the panel shows "credentials unreadable, re-enter keys".
     */
    public static function isUnreadable(Model $model, string $attribute): bool
    {
        return $model->getRawOriginal($attribute) !== null
            && self::readSafely($model, $attribute) === null;
    }
}
