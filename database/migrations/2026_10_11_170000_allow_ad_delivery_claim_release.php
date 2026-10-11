<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retryable conversion deliveries return from claimed to pending.
 * The original guard allowed that step only from failed (manual retry).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement($this->postgresFunction());

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS ad_conversion_deliveries_guard_update');
        DB::statement($this->sqliteTrigger());
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement($this->postgresFunction(allowClaimRelease: false));

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS ad_conversion_deliveries_guard_update');
        DB::statement($this->sqliteTrigger(allowClaimRelease: false));
    }

    private function postgresFunction(bool $allowClaimRelease = true): string
    {
        $release = $allowClaimRelease
            ? "OR (OLD.status = 'claimed' AND NEW.status = 'pending')"
            : '';

        return <<<SQL
CREATE OR REPLACE FUNCTION ad_conversion_deliveries_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.status IS DISTINCT FROM OLD.status
        AND NOT (
            (OLD.status = 'pending' AND NEW.status = 'claimed')
            OR (OLD.status = 'claimed' AND NEW.status IN ('sent', 'skipped', 'failed'))
            {$release}
            OR (OLD.status = 'failed' AND NEW.status = 'pending')
        )
    THEN
        RAISE EXCEPTION 'ad_conversion_delivery_status';
    END IF;

    IF OLD.status = 'sent'
        AND (
            NEW.ad_conversion_event_id IS DISTINCT FROM OLD.ad_conversion_event_id
            OR NEW.ad_platform_account_id IS DISTINCT FROM OLD.ad_platform_account_id
            OR NEW.identifiers_used::jsonb IS DISTINCT FROM OLD.identifiers_used::jsonb
        )
    THEN
        RAISE EXCEPTION 'ad_conversion_delivery_immutable';
    END IF;

    RETURN NEW;
END;
$$;
SQL;
    }

    private function sqliteTrigger(bool $allowClaimRelease = true): string
    {
        $release = $allowClaimRelease
            ? "OR (OLD.status = 'claimed' AND NEW.status = 'pending')"
            : '';

        return <<<SQL
CREATE TRIGGER ad_conversion_deliveries_guard_update
BEFORE UPDATE ON ad_conversion_deliveries
FOR EACH ROW
WHEN (
    (
        NEW.status IS NOT OLD.status
        AND NOT (
            (OLD.status = 'pending' AND NEW.status = 'claimed')
            OR (OLD.status = 'claimed' AND NEW.status IN ('sent', 'skipped', 'failed'))
            {$release}
            OR (OLD.status = 'failed' AND NEW.status = 'pending')
        )
    )
    OR (
        OLD.status = 'sent'
        AND (
            NEW.ad_conversion_event_id IS NOT OLD.ad_conversion_event_id
            OR NEW.ad_platform_account_id IS NOT OLD.ad_platform_account_id
            OR NEW.identifiers_used IS NOT OLD.identifiers_used
        )
    )
)
BEGIN
    SELECT CASE
        WHEN NEW.status IS NOT OLD.status
            AND NOT (
                (OLD.status = 'pending' AND NEW.status = 'claimed')
                OR (OLD.status = 'claimed' AND NEW.status IN ('sent', 'skipped', 'failed'))
                {$release}
                OR (OLD.status = 'failed' AND NEW.status = 'pending')
            )
        THEN RAISE(ABORT, 'ad_conversion_delivery_status')
        ELSE RAISE(ABORT, 'ad_conversion_delivery_immutable')
    END;
END
SQL;
    }
};
