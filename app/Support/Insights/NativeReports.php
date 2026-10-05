<?php

declare(strict_types=1);

namespace App\Support\Insights;

/**
 * Static registry of shipped native Insights reports.
 * Seeder inserts one system row per entry; mismatches surface via insights:check.
 *
 * @phpstan-type NativeReportEntry array{label_key: string, icon: string, section: string|null}
 */
final class NativeReports
{
    /**
     * Nav order (panel navigation.ts). Keys are URL segments.
     *
     * @var array<string, NativeReportEntry>
     */
    private const REPORTS = [
        'dashboard' => [
            'label_key' => 'insights.reports.dashboard.label',
            'icon' => 'i-lucide-layout-dashboard',
            'section' => 'overview',
        ],
        'rent-roll' => [
            'label_key' => 'insights.reports.rent_roll.label',
            'icon' => 'i-lucide-scroll-text',
            'section' => 'operations',
        ],
        'occupancy' => [
            'label_key' => 'insights.reports.occupancy.label',
            'icon' => 'i-lucide-pie-chart',
            'section' => 'operations',
        ],
        'ageing' => [
            'label_key' => 'insights.reports.ageing.label',
            'icon' => 'i-lucide-calendar-clock',
            'section' => 'operations',
        ],
        'collections' => [
            'label_key' => 'insights.reports.collections.label',
            'icon' => 'i-lucide-hand-coins',
            'section' => 'operations',
        ],
        'deposit-liability' => [
            'label_key' => 'insights.reports.deposit_liability.label',
            'icon' => 'i-lucide-landmark',
            'section' => 'operations',
        ],
        'daily-close' => [
            'label_key' => 'insights.reports.daily_close.label',
            'icon' => 'i-lucide-wallet',
            'section' => 'operations',
        ],
        'movement' => [
            'label_key' => 'insights.reports.movement.label',
            'icon' => 'i-lucide-arrow-left-right',
            'section' => 'operations',
        ],
        'funnel' => [
            'label_key' => 'insights.reports.funnel.label',
            'icon' => 'i-lucide-filter',
            'section' => 'operations',
        ],
        'demo' => [
            'label_key' => 'insights.reports.demo.label',
            'icon' => 'i-lucide-table',
            'section' => 'operations',
        ],
        'revenue' => [
            'label_key' => 'insights.reports.revenue.label',
            'icon' => 'i-lucide-trending-up',
            'section' => 'revenue',
        ],
        'rate-management' => [
            'label_key' => 'insights.reports.rate_management.label',
            'icon' => 'i-lucide-gauge',
            'section' => 'revenue',
        ],
        'length-of-stay' => [
            'label_key' => 'insights.reports.length_of_stay.label',
            'icon' => 'i-lucide-hourglass',
            'section' => 'customers',
        ],
        'delinquency-trend' => [
            'label_key' => 'insights.reports.delinquency_trend.label',
            'icon' => 'i-lucide-chart-area',
            'section' => 'operations',
        ],
    ];

    /**
     * @return array<string, NativeReportEntry>
     */
    public static function all(): array
    {
        return self::REPORTS;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::REPORTS);
    }

    public static function has(string $nativeKey): bool
    {
        return isset(self::REPORTS[$nativeKey]);
    }

    /**
     * @return NativeReportEntry|null
     */
    public static function get(string $nativeKey): ?array
    {
        return self::REPORTS[$nativeKey] ?? null;
    }
}
