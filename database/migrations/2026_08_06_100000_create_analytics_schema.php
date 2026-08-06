<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporting contract for external BI (Metabase). Postgres-only — SQLite no-op.
 *
 * Nothing in app/ may query analytics.*. Role metabase_ro is created by the
 * operator runbook in docs/ops/, not by this migration.
 *
 * Occupancy precedence in mv_unit_state_daily must stay in sync with
 * App\Support\Occupancy\Availability (occupancy wins, earliest non-overlock
 * hold, exclusive ends).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS analytics');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_revenue AS
            SELECT
                c.id                AS charge_id,
                ct.id               AS contract_id,
                s.id                AS site_id,
                s.name              AS site_name,
                c.currency          AS currency,
                s.timezone          AS site_timezone,
                c.charge_type,
                c.net_amount,
                c.tax_amount,
                c.amount            AS gross_amount,
                c.due_date,
                c.period_start      AS period_start_local,
                c.period_end        AS period_end_local,
                date_trunc('month', c.period_start::timestamp) AS period_month_local
            FROM charges c
            JOIN contracts ct ON ct.id = c.contract_id
            JOIN contract_items ci
                ON ci.contract_id = ct.id
                AND ci.item_type = 'unit'
                AND ci.effective_to IS NULL
            JOIN units u ON u.id = ci.item_id
            JOIN sites s ON s.id = u.site_id
            WHERE c.charge_type NOT IN ('deposit', 'write_off', 'refund')
              AND c.reversal_of_charge_id IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM charges r WHERE r.reversal_of_charge_id = c.id
              )
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_payments AS
            SELECT
                p.id                AS payment_id,
                ct.id               AS contract_id,
                s.id                AS site_id,
                s.name              AS site_name,
                p.currency          AS currency,
                s.timezone          AS site_timezone,
                p.method            AS payment_method_type,
                p.amount,
                p.received_on,
                p.created_at
            FROM payments p
            JOIN contracts ct ON ct.id = p.contract_id
            JOIN contract_items ci
                ON ci.contract_id = ct.id
                AND ci.item_type = 'unit'
                AND ci.effective_to IS NULL
            JOIN units u ON u.id = ci.item_id
            JOIN sites s ON s.id = u.site_id
            WHERE p.reversal_of_payment_id IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM payments r WHERE r.reversal_of_payment_id = p.id
              )
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_rent_roll AS
            SELECT
                o.id                AS occupancy_id,
                u.id                AS unit_id,
                u.unit_number,
                uc.id               AS unit_class_id,
                uc.code             AS unit_class_code,
                s.id                AS site_id,
                s.name              AS site_name,
                ct.id               AS contract_id,
                ct.contact_id,
                coalesce(nullif(trim(both from concat_ws(' ', c.first_name, c.last_name)), ''), null) AS contact_name,
                pr.amount           AS current_period_net_amount,
                pr.currency         AS currency,
                o.started_on,
                ct.billed_through
            FROM unit_occupancies o
            JOIN units u ON u.id = o.unit_id
            JOIN unit_classes uc ON uc.id = u.unit_class_id
            JOIN sites s ON s.id = u.site_id
            JOIN contracts ct ON ct.id = o.contract_id
            JOIN contacts c ON c.id = ct.contact_id
            LEFT JOIN contract_items ci
                ON ci.contract_id = ct.id
                AND ci.item_type = 'unit'
                AND ci.item_id = u.id
                AND ci.effective_to IS NULL
            LEFT JOIN prices pr ON pr.id = ci.price_id
            WHERE o.started_on <= CURRENT_DATE
              AND (o.ended_on IS NULL OR o.ended_on > CURRENT_DATE)
            SQL);

        // Precedence mirrors App\Support\Occupancy\Availability: occupancy wins,
        // else earliest unreleased non-overlock hold, else available. Ends are
        // exclusive (ended_on / ends_on > day). Keep both in sync.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW analytics.mv_unit_state_daily AS
            SELECT
                d.day,
                u.id AS unit_id,
                u.site_id,
                u.unit_class_id,
                CASE
                    WHEN o.id IS NOT NULL THEN 'occupied'
                    WHEN h.id IS NOT NULL THEN h.hold_type
                    ELSE 'available'
                END AS state,
                o.contract_id
            FROM generate_series(
                    COALESCE((SELECT min(started_on) FROM unit_occupancies), CURRENT_DATE),
                    CURRENT_DATE,
                    interval '1 day'
                ) AS d(day)
            CROSS JOIN units u
            LEFT JOIN LATERAL (
                SELECT o.*
                FROM unit_occupancies o
                WHERE o.unit_id = u.id
                  AND o.started_on <= d.day
                  AND (o.ended_on IS NULL OR o.ended_on > d.day)
                LIMIT 1
            ) o ON true
            LEFT JOIN LATERAL (
                SELECT h.*
                FROM unit_holds h
                WHERE h.unit_id = u.id
                  AND h.released_at IS NULL
                  AND h.hold_type <> 'overlock'
                  AND h.starts_on <= d.day
                  AND (h.ends_on IS NULL OR h.ends_on > d.day)
                ORDER BY h.created_at, h.id
                LIMIT 1
            ) h ON true
            SQL);

        DB::statement('CREATE UNIQUE INDEX mv_unit_state_daily_day_unit_uidx ON analytics.mv_unit_state_daily (day, unit_id)');
        DB::statement('CREATE INDEX mv_unit_state_daily_site_day_idx ON analytics.mv_unit_state_daily (site_id, day)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_pipeline_events AS
            SELECT
                'contact.created'::text AS event_type,
                ct.created_at AS occurred_at,
                NULL::bigint AS site_id,
                'contact'::text AS subject_type,
                ct.id AS subject_id
            FROM contacts ct

            UNION ALL

            SELECT
                'deal.created',
                d.created_at,
                d.site_id,
                'deal',
                d.id
            FROM deals d

            UNION ALL

            SELECT
                'deal.stage_changed',
                al.created_at,
                d.site_id,
                'deal',
                d.id
            FROM activity_log al
            JOIN deals d ON d.id = al.subject_id AND al.subject_type = 'deal'
            WHERE al.description = 'deal.stage_changed'

            UNION ALL

            SELECT
                'offer.sent',
                o.sent_at,
                d.site_id,
                'offer',
                o.id
            FROM offers o
            JOIN deals d ON d.id = o.deal_id
            WHERE o.sent_at IS NOT NULL

            UNION ALL

            SELECT
                'offer.accepted',
                o.accepted_at,
                d.site_id,
                'offer',
                o.id
            FROM offers o
            JOIN deals d ON d.id = o.deal_id
            WHERE o.accepted_at IS NOT NULL

            UNION ALL

            SELECT
                'reservation.created',
                r.created_at,
                COALESCE(d.site_id, u.site_id),
                'reservation',
                r.id
            FROM reservations r
            LEFT JOIN deals d ON d.id = r.deal_id
            JOIN units u ON u.id = r.unit_id

            UNION ALL

            SELECT
                'contract.signed',
                c.signed_at,
                s.id,
                'contract',
                c.id
            FROM contracts c
            JOIN contract_items ci
                ON ci.contract_id = c.id
                AND ci.item_type = 'unit'
                AND ci.effective_to IS NULL
            JOIN units u ON u.id = ci.item_id
            JOIN sites s ON s.id = u.site_id
            WHERE c.signed_at IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP SCHEMA IF EXISTS analytics CASCADE');
    }
};
