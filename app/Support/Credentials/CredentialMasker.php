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
    public static function readSafely(Model $model, string $attribute): mixed
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

    /**
     * Mask secret fields in a credentials map for API responses.
     *
     * @param  array<string, mixed>|null  $credentials
     * @param  array<string, array{label: string, secret: bool}>  $fields
     * @return array<string, array{masked: string|null, has_value: bool}>
     */
    public static function maskFields(?array $credentials, array $fields): array
    {
        $out = [];

        foreach ($fields as $key => $meta) {
            $value = $credentials[$key] ?? null;
            $string = is_string($value) ? $value : null;
            $out[$key] = [
                'masked' => ($meta['secret'] ?? true) ? self::mask($string) : ($string !== null && $string !== '' ? $string : null),
                'has_value' => $string !== null && $string !== '',
            ];
        }

        return $out;
    }

    /**
     * Merge submitted credential fields onto existing ones. Blank secret
     * fields leave the stored value unchanged.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $submitted
     * @param  array<string, array{label: string, secret: bool}>  $fields
     * @return array<string, mixed>
     */
    public static function mergeFields(array $existing, array $submitted, array $fields): array
    {
        $merged = $existing;

        foreach ($fields as $key => $_meta) {
            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            $value = $submitted[$key];

            if (! is_string($value) || CredentialField::isBlank($value)) {
                continue;
            }

            $merged[$key] = CredentialField::normalize($value);
        }

        return $merged;
    }

    /**
     * First secret field value suitable for audit last-4 masking.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, array{label: string, secret: bool}>  $fields
     */
    public static function primarySecret(array $credentials, array $fields): ?string
    {
        foreach ($fields as $key => $meta) {
            if (! ($meta['secret'] ?? true)) {
                continue;
            }

            $value = $credentials[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach ($credentials as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
