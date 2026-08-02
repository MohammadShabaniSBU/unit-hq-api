<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Site;

/**
 * Site-country → UI/content locale (S03 interim rule, formalised for templates).
 */
final class SiteLocale
{
    /** @var list<string> */
    public const ALLOWED = ['en', 'es', 'fr'];

    public static function for(?Site $site): string
    {
        if ($site === null) {
            return 'en';
        }

        $site->loadMissing('country');
        $code = strtoupper((string) ($site->country?->code ?? ''));

        return match ($code) {
            'ES' => 'es',
            'FR' => 'fr',
            default => 'en',
        };
    }
}
