<?php

declare(strict_types=1);

namespace App\Support\Communications;

/**
 * HMAC-signed public token embedding a normalized email address for
 * List-Unsubscribe one-click posts.
 */
final class UnsubscribeToken
{
    public static function issue(string $email): string
    {
        $normalized = ContactChannelMatcher::normalizeEmail($email);
        $sig = hash_hmac('sha256', $normalized, self::key());

        return rtrim(strtr(base64_encode($normalized.'|'.$sig), '+/', '-_'), '=');
    }

    public static function addressFrom(string $token): ?string
    {
        $padded = strtr($token, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($padded, true);
        if ($raw === false || ! str_contains($raw, '|')) {
            return null;
        }

        [$email, $sig] = explode('|', $raw, 2);
        $expected = hash_hmac('sha256', $email, self::key());

        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $normalized = ContactChannelMatcher::normalizeEmail($email);

        return $normalized !== '' ? $normalized : null;
    }

    public static function url(string $email): string
    {
        return rtrim((string) config('app.url'), '/').'/api/comms/unsubscribe/'.self::issue($email);
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }
}
