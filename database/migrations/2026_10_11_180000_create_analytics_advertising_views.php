<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paid-advertising views for external BI (S32-06).
 * Postgres-only — SQLite no-op. App code must not query analytics.*.
 * Click ids, landing URLs, and visitor keys stay off the touch view.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_ad_spend_daily AS
            SELECT
                c.id AS campaign_id,
                c.name AS campaign_name,
                a.provider,
                c.site_id,
                c.unit_class_id,
                s.spend_date,
                s.impressions,
                s.clicks,
                s.cost,
                s.currency,
                a.timezone AS account_timezone
            FROM ad_spend_daily s
            JOIN ad_campaigns c ON c.id = s.ad_campaign_id
            JOIN ad_platform_accounts a ON a.id = c.ad_platform_account_id
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_marketing_touches AS
            SELECT
                t.id AS touch_id,
                t.contact_id,
                t.occurred_at,
                t.site_id,
                t.platform,
                t.utm_source,
                t.utm_medium,
                t.utm_campaign
            FROM marketing_touches t
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_ad_conversion_deliveries AS
            SELECT
                e.event_key,
                a.provider,
                d.status,
                d.skip_reason,
                e.occurred_at,
                d.processed_at,
                e.value,
                e.currency
            FROM ad_conversion_deliveries d
            JOIN ad_conversion_events e ON e.id = d.ad_conversion_event_id
            JOIN ad_platform_accounts a ON a.id = d.ad_platform_account_id
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS analytics.v_ad_conversion_deliveries');
        DB::statement('DROP VIEW IF EXISTS analytics.v_marketing_touches');
        DB::statement('DROP VIEW IF EXISTS analytics.v_ad_spend_daily');
    }
};
