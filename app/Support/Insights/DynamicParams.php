<?php

declare(strict_types=1);

namespace App\Support\Insights;

/**
 * Whitelist of dynamic insight param keys (I3).
 * Resolve implementation ships in task 04; this task only validates keys on write.
 */
final class DynamicParams
{
    /**
     * Stable key → declared type (for task 05 type-checks).
     *
     * @var array<string, string>
     */
    private const KEYS = [
        'current_site_id' => 'int',
        'visible_site_ids' => 'array<int>',
        'current_employee_id' => 'int',
        'site_currency' => 'string',
        'site_timezone' => 'string',
        'today' => 'date',
        'month_start' => 'date',
        'month_end' => 'date',
        'year_start' => 'date',
        'locale' => 'string',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::KEYS);
    }

    public static function has(string $key): bool
    {
        return isset(self::KEYS[$key]);
    }

    public static function typeOf(string $key): ?string
    {
        return self::KEYS[$key] ?? null;
    }
}
