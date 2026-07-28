<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Support\Facades\Config;

/**
 * Refuses to register inbound webhooks (Brevo, Stripe, …) against a base URL
 * that a third-party provider could never reach — localhost, private/loopback
 * IP ranges, or a missing APP_URL. Providers cannot deliver to those hosts, so
 * "successfully configured" would be a lie.
 */
final class PublicUrlGuard
{
    public static function isPublic(?string $url = null): bool
    {
        $url ??= (string) Config::get('app.url');

        if ($url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            return $isPublicIp !== false;
        }

        // Non-IP hostnames (real domains) are treated as public; DNS
        // resolvability is not checked here to keep this a pure/offline guard.
        return true;
    }

    /**
     * @throws PublicUrlUnreachableException
     */
    public static function assertPublic(?string $url = null): void
    {
        if (! self::isPublic($url)) {
            throw new PublicUrlUnreachableException(
                'Cannot register a webhook: the configured public URL is missing, localhost, or a private address.'
            );
        }
    }

    public static function baseUrl(): string
    {
        return rtrim((string) Config::get('app.url'), '/');
    }

    public static function webhookUrl(string $path): string
    {
        return self::baseUrl().'/'.ltrim($path, '/');
    }
}
