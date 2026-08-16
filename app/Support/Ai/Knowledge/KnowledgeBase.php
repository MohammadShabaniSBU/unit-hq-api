<?php

declare(strict_types=1);

namespace App\Support\Ai\Knowledge;

use App\Models\Site;

final class KnowledgeBase
{
    /** @var list<string> */
    public const KEYS = [
        'access_hours',
        'insurance_required',
        'notice_period',
        'prohibited_items',
        'overlock_policy',
        'deposit',
        'id_required',
        'payment_methods',
    ];

    public static function snippet(string $key, string $locale, ?Site $site = null): ?string
    {
        if (! in_array($key, self::KEYS, true)) {
            return null;
        }

        $entry = config('ai-knowledge.'.self::normalizeLocale($locale).'.'.$key);
        if (! is_array($entry)) {
            return null;
        }

        $default = $entry['default'] ?? null;
        if (! is_string($default) || $default === '') {
            return null;
        }

        $code = $site?->code;
        if ($code !== null && $code !== '' && isset($entry['sites'][$code]) && is_string($entry['sites'][$code])) {
            return $entry['sites'][$code];
        }

        return $default;
    }

    public static function normalizeLocale(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
    }
}
