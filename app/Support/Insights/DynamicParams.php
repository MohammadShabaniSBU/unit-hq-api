<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Models\Site;
use App\Support\Auth\Permission;
use App\Support\Insights\Exceptions\UnknownDynamicParamKey;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Whitelist of dynamic insight param keys (I3).
 * Types live here (code), never in the database — task 05 type-checks against typeOf().
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

    /**
     * Resolve a whitelisted dynamic key against mint-time context.
     * Unknown keys fail closed — never silent null.
     */
    public static function resolve(string $key, DynamicParamContext $ctx): mixed
    {
        if (! self::has($key)) {
            throw new UnknownDynamicParamKey($key);
        }

        return match ($key) {
            'current_site_id' => $ctx->applySiteScope ? $ctx->siteId : null,
            'visible_site_ids' => self::visibleSiteIds($ctx),
            'current_employee_id' => $ctx->employee->id,
            'site_currency' => $ctx->applySiteScope ? ($ctx->site?->currency) : null,
            'site_timezone' => $ctx->applySiteScope ? ($ctx->site?->timezone) : null,
            'today' => self::siteDate($ctx, fn (CarbonImmutable $now): string => $now->toDateString()),
            'month_start' => self::siteDate($ctx, fn (CarbonImmutable $now): string => $now->startOfMonth()->toDateString()),
            'month_end' => self::siteDate($ctx, fn (CarbonImmutable $now): string => $now->endOfMonth()->toDateString()),
            'year_start' => self::siteDate($ctx, fn (CarbonImmutable $now): string => $now->startOfYear()->toDateString()),
            'locale' => $ctx->locale,
            default => throw new InvalidArgumentException('Unhandled dynamic insight param key: '.$key),
        };
    }

    /**
     * @return list<int>
     */
    private static function visibleSiteIds(DynamicParamContext $ctx): array
    {
        $granted = $ctx->employee->siteIdsFor(Permission::ReportView);

        if ($granted === []) {
            return [];
        }

        if ($granted === null) {
            /** @var list<int> $ids */
            $ids = Site::query()->active()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

            return $ids;
        }

        return array_values(array_map(intval(...), $granted));
    }

    /**
     * @param  callable(CarbonImmutable): string  $format
     */
    private static function siteDate(DynamicParamContext $ctx, callable $format): ?string
    {
        if (! $ctx->applySiteScope || $ctx->site === null || $ctx->site->timezone === null || $ctx->site->timezone === '') {
            return null;
        }

        $now = CarbonImmutable::now($ctx->site->timezone);

        return $format($now);
    }
}
