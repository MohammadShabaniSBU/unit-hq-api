<?php

declare(strict_types=1);

namespace App\Support\Insights;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Minimal HS256 JWT encode/decode for Metabase static embeds.
 * No third-party JWT package — keeps the signing secret path local and tiny.
 */
final class Hs256Jwt
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload, string $secret): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('JWT secret must not be empty.');
        }

        $header = self::base64UrlEncode(json_encode(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR
        ));
        $body = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = self::base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $secret, true));

        return $header.'.'.$body.'.'.$sig;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $jwt, string $secret): array
    {
        if ($secret === '') {
            throw new InvalidArgumentException('JWT secret must not be empty.');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Malformed JWT.');
        }

        [$headerB64, $bodyB64, $sigB64] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $headerB64.'.'.$bodyB64, $secret, true));

        if (! hash_equals($expected, $sigB64)) {
            throw new UnexpectedValueException('Invalid JWT signature.');
        }

        $json = self::base64UrlDecode($bodyB64);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException('JWT payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($padded, true);
        if ($raw === false) {
            throw new UnexpectedValueException('Invalid JWT base64url segment.');
        }

        return $raw;
    }
}
