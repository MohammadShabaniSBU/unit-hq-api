<?php

declare(strict_types=1);

namespace App\Support\Insights;

use Illuminate\Validation\ValidationException;

/**
 * Rejects non-https iframe URL templates and hosts outside the operator
 * allowlist. An empty allowlist fails closed.
 */
final class IframeHostGuard
{
    public static function assertAllowed(string $template): void
    {
        $scheme = strtolower((string) parse_url($template, PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            throw ValidationException::withMessages([
                'base_url' => [__('errors.insights.iframe_https_required')],
            ]);
        }

        $host = strtolower((string) parse_url($template, PHP_URL_HOST));

        if ($host === '') {
            throw ValidationException::withMessages([
                'base_url' => [__('errors.insights.iframe_host_required')],
            ]);
        }

        /** @var list<string> $allowlist */
        $allowlist = array_map(
            'strtolower',
            config('insights.iframe_host_allowlist', [])
        );

        if ($allowlist === [] || ! in_array($host, $allowlist, true)) {
            throw ValidationException::withMessages([
                'base_url' => [__('errors.insights.iframe_host_not_allowlisted', ['host' => $host])],
            ]);
        }
    }

    /**
     * Strip `{param}` placeholders so a HEAD probe can hit a concrete path.
     */
    public static function probeUrl(string $template): string
    {
        $stripped = (string) preg_replace('/\{[^}]+\}/', '', $template);
        $stripped = (string) preg_replace('#(?<!:)/{2,}#', '/', $stripped);

        return rtrim($stripped, '?&');
    }
}
