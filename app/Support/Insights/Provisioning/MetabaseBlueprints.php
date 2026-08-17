<?php

declare(strict_types=1);

namespace App\Support\Insights\Provisioning;

/**
 * Shipped Metabase dashboard blueprints. SQL is bounded to analytics.*
 * and never sums money across currencies (invariant 30).
 *
 * @phpstan-type BlueprintCard array{
 *     name: string,
 *     display: string,
 *     sql: string,
 *     visualization_settings: array<string, mixed>,
 *     row: int,
 *     col: int,
 *     size_x: int,
 *     size_y: int
 * }
 * @phpstan-type BlueprintEntry array{
 *     mirrors: string|null,
 *     fidelity: 'exact'|'subset',
 *     title: string,
 *     description: string,
 *     section: string,
 *     icon: string,
 *     site_param: bool,
 *     cards: list<BlueprintCard>
 * }
 */
final class MetabaseBlueprints
{
    /**
     * Native keys that cannot be provisioned yet, mapped to the additive
     * analytics change they need. Printed on every insights:provision run.
     *
     * @var array<string, string>
     */
    public const BLOCKED = [
        'ageing' => 'needs analytics.v_open_charges (ledger current-state ageing buckets)',
        'collections' => 'needs analytics.v_allocations (collected vs charged by period)',
        'deposit-liability' => 'needs analytics.v_deposit_liability',
        'movement' => 'needs analytics.v_movement_events',
        'daily-close' => 'needs analytics.v_allocations plus till/cash breakdown',
        'funnel' => 'conversion half needs deal_id correlation and offer.viewed on v_pipeline_events',
        'dashboard' => 'native by design (card-zoom of live query classes)',
        'demo' => 'native by design',
    ];

    public const SITES_LOOKUP_NAME = 'Sites';

    public const SITES_LOOKUP_REF = '_sites';

    private const BLUEPRINTS = [
        'rent-roll' => [
            'mirrors' => 'rent-roll',
            'fidelity' => 'subset',
            'title' => 'Rent roll',
            'description' => 'Open occupancies and in-place rent from analytics.v_rent_roll. Subset of the native rent-roll: no balance owed, overdue, deposit held, or delinquency days — those are ledger current-state figures the reporting contract does not expose.',
            'section' => 'operations',
            'icon' => 'i-lucide-scroll-text',
            'site_param' => true,
            'cards' => [
                [
                    'name' => 'Open occupancies',
                    'display' => 'scalar',
                    'sql' => <<<'SQL'
-- report-definitions.md — Rent roll
SELECT COUNT(*) AS open_occupancies
FROM analytics.v_rent_roll
WHERE 1=1
[[AND site_id = {{site_id}}]]
SQL,
                    'visualization_settings' => [],
                    'row' => 0,
                    'col' => 0,
                    'size_x' => 6,
                    'size_y' => 4,
                ],
                [
                    'name' => 'In-place rent by site',
                    'display' => 'bar',
                    'sql' => <<<'SQL'
-- report-definitions.md — Rent roll (in-place rent)
SELECT site_name, currency, SUM(current_period_net_amount) AS in_place_rent
FROM analytics.v_rent_roll
WHERE 1=1
[[AND site_id = {{site_id}}]]
GROUP BY site_name, currency
ORDER BY site_name, currency
SQL,
                    'visualization_settings' => [],
                    'row' => 0,
                    'col' => 6,
                    'size_x' => 12,
                    'size_y' => 8,
                ],
                [
                    'name' => 'Open occupancies detail',
                    'display' => 'table',
                    'sql' => <<<'SQL'
-- report-definitions.md — Rent roll
SELECT
    unit_number,
    unit_class_code,
    site_name,
    contact_name,
    current_period_net_amount,
    currency,
    started_on,
    billed_through
FROM analytics.v_rent_roll
WHERE 1=1
[[AND site_id = {{site_id}}]]
ORDER BY site_name, unit_number
SQL,
                    'visualization_settings' => [],
                    'row' => 8,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 10,
                ],
            ],
        ],
        'occupancy' => [
            'mirrors' => 'occupancy',
            'fidelity' => 'subset',
            'title' => 'Occupancy',
            'description' => 'Unit and area occupancy of enabled units from analytics.mv_unit_state_daily. Figures are as of the last analytics:refresh, not live — the native occupancy report is the live figure. Subset: no economic occupancy (catalogue as-of-day is not on the MV).',
            'section' => 'operations',
            'icon' => 'i-lucide-pie-chart',
            'site_param' => true,
            'cards' => [
                [
                    'name' => 'Unit occupancy (last refresh)',
                    'display' => 'scalar',
                    'sql' => <<<'SQL'
-- report-definitions.md — Occupancy / Unit occupancy
-- as_of is max(day) from the last analytics:refresh, not live.
WITH asof AS (
    SELECT max(day) AS as_of FROM analytics.mv_unit_state_daily
)
SELECT
    CASE
        WHEN COUNT(*) FILTER (
            WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
        ) = 0 THEN NULL
        ELSE ROUND(
            100.0 * COUNT(*) FILTER (WHERE m.enabled AND m.state = 'occupied')
            / COUNT(*) FILTER (
                WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
            )
        , 1)
    END AS unit_rate,
    COUNT(*) FILTER (WHERE m.enabled AND m.state = 'occupied') AS occupied_units,
    COUNT(*) FILTER (
        WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
    ) AS rentable_units,
    a.as_of
FROM analytics.mv_unit_state_daily m
CROSS JOIN asof a
WHERE m.day = a.as_of
[[AND m.site_id = {{site_id}}]]
GROUP BY a.as_of
SQL,
                    'visualization_settings' => [
                        'scalar.field' => 'unit_rate',
                        'column_settings' => [
                            '["name","unit_rate"]' => [
                                'suffix' => '%',
                                'decimals' => 1,
                            ],
                        ],
                    ],
                    'row' => 0,
                    'col' => 0,
                    'size_x' => 9,
                    'size_y' => 4,
                ],
                [
                    'name' => 'Area occupancy (last refresh)',
                    'display' => 'scalar',
                    'sql' => <<<'SQL'
-- report-definitions.md — Occupancy / Area occupancy
-- Area is unit_classes.size. as_of is max(day) from the last analytics:refresh, not live.
WITH asof AS (
    SELECT max(day) AS as_of FROM analytics.mv_unit_state_daily
)
SELECT
    CASE
        WHEN COALESCE(SUM(m.area) FILTER (
            WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
        ), 0) = 0 THEN NULL
        ELSE ROUND(
            100.0 * COALESCE(SUM(m.area) FILTER (WHERE m.enabled AND m.state = 'occupied'), 0)
            / SUM(m.area) FILTER (
                WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
            )
        , 1)
    END AS area_rate,
    COALESCE(SUM(m.area) FILTER (WHERE m.enabled AND m.state = 'occupied'), 0) AS occupied_area,
    COALESCE(SUM(m.area) FILTER (
        WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
    ), 0) AS rentable_area,
    a.as_of
FROM analytics.mv_unit_state_daily m
CROSS JOIN asof a
WHERE m.day = a.as_of
[[AND m.site_id = {{site_id}}]]
GROUP BY a.as_of
SQL,
                    'visualization_settings' => [
                        'scalar.field' => 'area_rate',
                        'column_settings' => [
                            '["name","area_rate"]' => [
                                'suffix' => '%',
                                'decimals' => 1,
                            ],
                        ],
                    ],
                    'row' => 0,
                    'col' => 9,
                    'size_x' => 9,
                    'size_y' => 4,
                ],
                [
                    'name' => 'Monthly unit occupancy',
                    'display' => 'line',
                    'sql' => <<<'SQL'
-- report-definitions.md — Occupancy / Unit occupancy (monthly)
-- Each point is month-end, or max(day) when the month is not yet complete in the MV.
WITH asof AS (
    SELECT max(day) AS as_of FROM analytics.mv_unit_state_daily
),
month_ends AS (
    SELECT LEAST(
        (date_trunc('month', a.as_of) - (g * interval '1 month') + interval '1 month' - interval '1 day')::date,
        a.as_of
    ) AS as_of
    FROM asof a
    CROSS JOIN generate_series(0, 11) AS g
)
SELECT
    me.as_of,
    COUNT(*) FILTER (WHERE m.enabled AND m.state = 'occupied') AS occupied_units,
    COUNT(*) FILTER (
        WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
    ) AS rentable_units,
    CASE
        WHEN COUNT(*) FILTER (
            WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
        ) = 0 THEN NULL
        ELSE ROUND(
            100.0 * COUNT(*) FILTER (WHERE m.enabled AND m.state = 'occupied')
            / COUNT(*) FILTER (
                WHERE m.enabled AND m.state NOT IN ('maintenance', 'damaged', 'staff_use', 'other')
            )
        , 1)
    END AS unit_rate
FROM month_ends me
JOIN analytics.mv_unit_state_daily m ON m.day = me.as_of
[[AND m.site_id = {{site_id}}]]
GROUP BY me.as_of
ORDER BY me.as_of
SQL,
                    'visualization_settings' => [],
                    'row' => 4,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 8,
                ],
            ],
        ],
        'revenue-trend' => [
            'mirrors' => null,
            'fidelity' => 'exact',
            'title' => 'Revenue trend',
            'description' => 'Net revenue from analytics.v_revenue, grouped by currency. Rolling 12 months. Matches analytics-schema.md (deposit, write_off, refund excluded; reversals netted).',
            'section' => 'operations',
            'icon' => 'i-lucide-line-chart',
            'site_param' => true,
            'cards' => [
                [
                    'name' => 'Net revenue by month',
                    'display' => 'line',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_revenue
SELECT period_month_local::date AS period_month, currency, SUM(net_amount) AS net_revenue
FROM analytics.v_revenue
WHERE period_month_local >= date_trunc('month', CURRENT_DATE) - interval '11 months'
[[AND site_id = {{site_id}}]]
GROUP BY period_month_local, currency
ORDER BY period_month_local, currency
SQL,
                    'visualization_settings' => [],
                    'row' => 0,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 8,
                ],
                [
                    'name' => 'Net revenue by charge type',
                    'display' => 'bar',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_revenue
SELECT charge_type, currency, SUM(net_amount) AS net_revenue
FROM analytics.v_revenue
WHERE period_month_local >= date_trunc('month', CURRENT_DATE) - interval '11 months'
[[AND site_id = {{site_id}}]]
GROUP BY charge_type, currency
ORDER BY charge_type, currency
SQL,
                    'visualization_settings' => [],
                    'row' => 8,
                    'col' => 0,
                    'size_x' => 9,
                    'size_y' => 8,
                ],
                [
                    'name' => 'Current month by site',
                    'display' => 'table',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_revenue
SELECT site_name, currency, SUM(net_amount) AS net_revenue
FROM analytics.v_revenue
WHERE period_month_local = date_trunc('month', CURRENT_DATE)
[[AND site_id = {{site_id}}]]
GROUP BY site_name, currency
ORDER BY site_name, currency
SQL,
                    'visualization_settings' => [],
                    'row' => 8,
                    'col' => 9,
                    'size_x' => 9,
                    'size_y' => 8,
                ],
            ],
        ],
        'payments-trend' => [
            'mirrors' => null,
            'fidelity' => 'exact',
            'title' => 'Payments trend',
            'description' => 'Payments from analytics.v_payments, grouped by currency. Rolling 12 months. Reversal children and reversed originals excluded.',
            'section' => 'operations',
            'icon' => 'i-lucide-banknote',
            'site_param' => true,
            'cards' => [
                [
                    'name' => 'Payments by month',
                    'display' => 'line',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_payments
SELECT date_trunc('month', received_on)::date AS received_month, currency, SUM(amount) AS amount
FROM analytics.v_payments
WHERE received_on >= date_trunc('month', CURRENT_DATE) - interval '11 months'
[[AND site_id = {{site_id}}]]
GROUP BY date_trunc('month', received_on), currency
ORDER BY received_month, currency
SQL,
                    'visualization_settings' => [],
                    'row' => 0,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 8,
                ],
                [
                    'name' => 'Payments by method',
                    'display' => 'bar',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_payments
SELECT payment_method_type, currency, SUM(amount) AS amount
FROM analytics.v_payments
WHERE received_on >= date_trunc('month', CURRENT_DATE) - interval '11 months'
[[AND site_id = {{site_id}}]]
GROUP BY payment_method_type, currency
ORDER BY payment_method_type, currency
SQL,
                    'visualization_settings' => [],
                    'row' => 8,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 8,
                ],
            ],
        ],
        'pipeline-events' => [
            'mirrors' => 'funnel',
            'fidelity' => 'subset',
            'title' => 'Pipeline events',
            'description' => 'Event volumes from analytics.v_pipeline_events. Subset of the native funnel: volumes only, no conversion (v_pipeline_events has no deal correlation and no offer.viewed).',
            'section' => 'operations',
            'icon' => 'i-lucide-filter',
            'site_param' => true,
            'cards' => [
                [
                    'name' => 'Events by month',
                    'display' => 'line',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_pipeline_events
SELECT date_trunc('month', occurred_at)::date AS event_month, event_type, COUNT(*) AS events
FROM analytics.v_pipeline_events
WHERE occurred_at >= date_trunc('month', CURRENT_DATE) - interval '11 months'
[[AND site_id = {{site_id}}]]
GROUP BY date_trunc('month', occurred_at), event_type
ORDER BY event_month, event_type
SQL,
                    'visualization_settings' => [],
                    'row' => 0,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 8,
                ],
                [
                    'name' => 'Events last 90 days',
                    'display' => 'bar',
                    'sql' => <<<'SQL'
-- analytics-schema.md — v_pipeline_events
SELECT event_type, COUNT(*) AS events
FROM analytics.v_pipeline_events
WHERE occurred_at >= CURRENT_DATE - interval '90 days'
[[AND site_id = {{site_id}}]]
GROUP BY event_type
ORDER BY event_type
SQL,
                    'visualization_settings' => [],
                    'row' => 8,
                    'col' => 0,
                    'size_x' => 18,
                    'size_y' => 8,
                ],
            ],
        ],
    ];

    /**
     * @return array<string, BlueprintEntry>
     */
    public static function all(): array
    {
        return self::BLUEPRINTS;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::BLUEPRINTS);
    }

    public static function has(string $key): bool
    {
        return isset(self::BLUEPRINTS[$key]);
    }

    /**
     * @return BlueprintEntry|null
     */
    public static function get(string $key): ?array
    {
        return self::BLUEPRINTS[$key] ?? null;
    }

    public static function hash(string $key): string
    {
        $entry = self::BLUEPRINTS[$key] ?? null;
        if ($entry === null) {
            throw new \InvalidArgumentException('Unknown blueprint: '.$key);
        }

        return hash('sha256', json_encode([
            'entry' => $entry,
            'parameters' => self::parameters(),
        ], JSON_THROW_ON_ERROR));
    }

    public static function sitesLookupSql(): string
    {
        return <<<'SQL'
SELECT id, name
FROM sites
ORDER BY name
SQL;
    }

    /**
     * @return array<string, mixed>
     */
    public static function templateTags(): array
    {
        return [
            'site_id' => [
                'id' => 'site_id',
                'name' => 'site_id',
                'display-name' => 'Site',
                'type' => 'number',
                'required' => false,
            ],
        ];
    }

    /**
     * Dashboard Site filter is an id dropdown. Template tags stay `number` so
     * locked embeds still pass a scalar current_site_id.
     *
     * @return list<array<string, mixed>>
     */
    public static function parameters(?int $sitesCardId = null): array
    {
        $param = [
            'id' => 'site_id',
            'name' => 'Site',
            'slug' => 'site_id',
            'type' => 'id',
            'sectionId' => 'id',
            'values_query_type' => 'list',
            'values_source_type' => 'card',
        ];

        if ($sitesCardId !== null) {
            $param['values_source_config'] = [
                'card_id' => $sitesCardId,
                'value_field' => ['field', 'id', ['base-type' => 'type/BigInteger']],
                'label_field' => ['field', 'name', ['base-type' => 'type/Text']],
            ];
        }

        return [$param];
    }

    /**
     * @return array<string, string>
     */
    public static function embeddingParams(): array
    {
        return [
            'site_id' => 'locked',
        ];
    }
}
