<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Latest consent per (contact, channel, list) for external BI.
 * Postgres-only — SQLite no-op. App code must not query analytics.*.
 * Evidence stays off the view (PII; redaction nulls it on the ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.v_consent_state AS
            SELECT DISTINCT ON (e.contact_id, e.channel, e.subscription_list_id)
                e.contact_id,
                e.channel,
                e.subscription_list_id,
                l.key AS list_key,
                e.action,
                e.source,
                e.occurred_at
            FROM consent_events e
            JOIN subscription_lists l ON l.id = e.subscription_list_id
            ORDER BY e.contact_id, e.channel, e.subscription_list_id, e.occurred_at DESC, e.id DESC
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS analytics.v_consent_state');
    }
};
