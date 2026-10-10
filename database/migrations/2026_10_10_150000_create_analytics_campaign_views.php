<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Campaign and consent-event views for external BI (S31).
 * Postgres-only — SQLite no-op. App code must not query analytics.*.
 * Evidence, addresses, bodies, and errors stay off the views.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_consent_events AS
            SELECT
                e.id,
                e.contact_id,
                e.channel,
                e.subscription_list_id,
                l.key AS list_key,
                e.action,
                e.source,
                e.occurred_at
            FROM consent_events e
            JOIN subscription_lists l ON l.id = e.subscription_list_id
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_campaigns AS
            SELECT
                c.id,
                c.type,
                c.name,
                c.status,
                c.subscription_list_id,
                l.key AS list_key,
                c.site_ids,
                c.approved_at,
                sends.window_start,
                sends.window_end,
                sends.email_template_family_id,
                sends.email_template_version_id,
                sends.sms_template_family_id,
                sends.sms_template_version_id
            FROM campaigns c
            LEFT JOIN subscription_lists l ON l.id = c.subscription_list_id
            LEFT JOIN LATERAL (
                SELECT
                    MIN(s.scheduled_at) AS window_start,
                    MAX(s.completed_at) AS window_end,
                    MAX(s.template_family_id) FILTER (WHERE s.channel = 'email') AS email_template_family_id,
                    MAX(s.template_version_id) FILTER (WHERE s.channel = 'email') AS email_template_version_id,
                    MAX(s.template_family_id) FILTER (WHERE s.channel = 'sms') AS sms_template_family_id,
                    MAX(s.template_version_id) FILTER (WHERE s.channel = 'sms') AS sms_template_version_id
                FROM campaign_sends s
                WHERE s.campaign_id = c.id
            ) sends ON true
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_campaign_recipients AS
            SELECT
                s.campaign_id,
                r.campaign_send_id,
                r.contact_id,
                s.channel,
                s.template_family_id,
                s.template_version_id,
                r.status,
                r.skip_reason,
                r.processed_at,
                COALESCE(flags.delivered, false) AS delivered,
                COALESCE(flags.bounced, false) AS bounced,
                COALESCE(flags.opened, false) AS opened,
                COALESCE(flags.clicked, false) AS clicked,
                COALESCE(flags.complained, false) AS complained
            FROM campaign_recipients r
            JOIN campaign_sends s ON s.id = r.campaign_send_id
            LEFT JOIN messages m ON m.id = r.message_id
            LEFT JOIN LATERAL (
                SELECT
                    bool_or(ev->>'status' = 'delivered') AS delivered,
                    bool_or(ev->>'status' = 'bounced') AS bounced,
                    bool_or(ev->>'status' = 'opened') AS opened,
                    bool_or(ev->>'status' = 'clicked') AS clicked,
                    bool_or(ev->>'status' = 'spam') AS complained
                FROM jsonb_array_elements(COALESCE(m.delivery_events::jsonb, '[]'::jsonb)) AS ev
            ) flags ON true
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS analytics.v_campaign_recipients');
        DB::statement('DROP VIEW IF EXISTS analytics.v_campaigns');
        DB::statement('DROP VIEW IF EXISTS analytics.v_consent_events');
    }
};
