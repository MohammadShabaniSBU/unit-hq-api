<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising accounts, touches, conversion outbox, campaigns, and spend (S32-00).
 *
 * CHECK predicates are the same on both drivers. Postgres gets named CHECK
 * constraints. SQLite cannot ALTER ADD CONSTRAINT, so it gets BEFORE INSERT
 * and BEFORE UPDATE triggers that RAISE(ABORT) under the same names.
 *
 * Immutability triggers exist on both drivers, same pattern as consent_events.
 * Eloquent guards cover a path that bypasses a trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createTables();

        Schema::table('signup_forms', function (Blueprint $table): void {
            $table->string('purpose', 16)->default('subscribe');
            $table->json('enquiry_fields')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresChecks();
            $this->createPostgresTriggers();

            return;
        }

        $this->createSqliteChecks();
        $this->createSqliteTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS marketing_touches_reject_mutation ON marketing_touches');
            DB::statement('DROP FUNCTION IF EXISTS marketing_touches_reject_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS ad_conversion_events_reject_mutation ON ad_conversion_events');
            DB::statement('DROP FUNCTION IF EXISTS ad_conversion_events_reject_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS ad_conversion_deliveries_guard ON ad_conversion_deliveries');
            DB::statement('DROP FUNCTION IF EXISTS ad_conversion_deliveries_guard()');
        } else {
            foreach ([
                'marketing_touches_reject_update',
                'marketing_touches_reject_delete',
                'ad_conversion_events_reject_update',
                'ad_conversion_events_reject_delete',
                'ad_conversion_deliveries_guard_update',
                'ace_value_has_currency_insert',
                'ace_value_has_currency_update',
                'acm_value_only_move_in_insert',
                'acm_value_only_move_in_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }

        Schema::dropIfExists('ad_spend_daily');
        Schema::dropIfExists('ad_conversion_deliveries');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_conversion_mappings');
        Schema::dropIfExists('ad_conversion_events');
        Schema::dropIfExists('marketing_touches');
        Schema::dropIfExists('ad_platform_accounts');

        Schema::table('signup_forms', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'enquiry_fields']);
        });
    }

    private function createTables(): void
    {
        Schema::create('ad_platform_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 16);
            $table->string('name', 128);
            $table->string('external_account_id', 64);
            $table->string('login_customer_id', 32)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('credentials');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_verified_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'external_account_id']);
        });

        Schema::create('marketing_touches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->char('visitor_key', 32)->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->string('platform', 16)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->json('click_ids')->default('{}');
            $table->string('landing_url', 1000)->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->foreignId('signup_form_id')->nullable()->constrained('signup_forms');
            $table->json('consent_snapshot')->default('{}');
            $table->timestampTz('created_at');
        });

        // message_threads already owns the name mt_contact_idx.
        DB::statement('CREATE INDEX marketing_touches_contact_idx ON marketing_touches (contact_id, occurred_at DESC)');
        DB::statement('CREATE INDEX mt_visitor_idx ON marketing_touches (visitor_key, occurred_at DESC) WHERE contact_id IS NULL');
        DB::statement('CREATE INDEX mt_platform_campaign_idx ON marketing_touches (platform, utm_campaign)');

        Schema::create('ad_conversion_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_platform_account_id')->constrained('ad_platform_accounts');
            $table->string('event_key', 24);
            $table->string('external_ref', 255);
            $table->string('value_rule', 24)->default('none');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['ad_platform_account_id', 'event_key']);
        });

        Schema::create('ad_conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 24);
            $table->string('dedupe_id', 64)->unique();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->decimal('value', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
        });

        Schema::create('ad_conversion_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_conversion_event_id')->constrained('ad_conversion_events')->cascadeOnDelete();
            $table->foreignId('ad_platform_account_id')->constrained('ad_platform_accounts');
            $table->foreignId('marketing_touch_id')->nullable()->constrained('marketing_touches')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('skip_reason', 32)->nullable();
            $table->json('identifiers_used')->default('[]');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestampsTz();

            $table->unique(['ad_platform_account_id', 'ad_conversion_event_id']);
        });

        DB::statement('CREATE INDEX acd_status_idx ON ad_conversion_deliveries (status, ad_platform_account_id)');

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_platform_account_id')->constrained('ad_platform_accounts');
            $table->string('external_id', 64);
            $table->string('name', 255);
            $table->string('status', 16);
            $table->string('channel_type', 32)->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->foreignId('unit_class_id')->nullable()->constrained('unit_classes');
            $table->string('utm_campaign_hint', 255)->nullable();
            $table->foreignId('mapped_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('mapped_at')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_synced_at');
            $table->timestampsTz();

            $table->unique(['ad_platform_account_id', 'external_id']);
        });

        Schema::create('ad_spend_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns');
            $table->date('spend_date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->char('currency', 3);
            $table->timestampTz('synced_at');

            $table->unique(['ad_campaign_id', 'spend_date']);
        });
    }

    private function createPostgresChecks(): void
    {
        DB::statement("ALTER TABLE ad_conversion_mappings ADD CONSTRAINT acm_value_only_move_in CHECK (value_rule = 'none' OR event_key = 'move_in')");
        DB::statement('ALTER TABLE ad_conversion_events ADD CONSTRAINT ace_value_has_currency CHECK ((value IS NULL) = (currency IS NULL))');
    }

    private function createPostgresTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION marketing_touches_reject_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'marketing_touch_immutable';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.visitor_key IS DISTINCT FROM OLD.visitor_key
        OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
        OR NEW.site_id IS DISTINCT FROM OLD.site_id
        OR NEW.platform IS DISTINCT FROM OLD.platform
        OR NEW.utm_source IS DISTINCT FROM OLD.utm_source
        OR NEW.utm_medium IS DISTINCT FROM OLD.utm_medium
        OR NEW.utm_campaign IS DISTINCT FROM OLD.utm_campaign
        OR NEW.signup_form_id IS DISTINCT FROM OLD.signup_form_id
        OR NEW.consent_snapshot::jsonb IS DISTINCT FROM OLD.consent_snapshot::jsonb
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'marketing_touch_immutable';
    END IF;

    IF NEW.contact_id IS DISTINCT FROM OLD.contact_id
        AND (OLD.contact_id IS NOT NULL OR NEW.contact_id IS NULL)
    THEN
        RAISE EXCEPTION 'marketing_touch_immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER marketing_touches_reject_mutation
BEFORE UPDATE OR DELETE ON marketing_touches
FOR EACH ROW
EXECUTE FUNCTION marketing_touches_reject_mutation();

CREATE OR REPLACE FUNCTION ad_conversion_events_reject_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'ad_conversion_event_immutable';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.event_key IS DISTINCT FROM OLD.event_key
        OR NEW.dedupe_id IS DISTINCT FROM OLD.dedupe_id
        OR NEW.contact_id IS DISTINCT FROM OLD.contact_id
        OR NEW.subject_type IS DISTINCT FROM OLD.subject_type
        OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
        OR NEW.site_id IS DISTINCT FROM OLD.site_id
        OR NEW.value IS DISTINCT FROM OLD.value
        OR NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'ad_conversion_event_immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ad_conversion_events_reject_mutation
BEFORE UPDATE OR DELETE ON ad_conversion_events
FOR EACH ROW
EXECUTE FUNCTION ad_conversion_events_reject_mutation();

CREATE OR REPLACE FUNCTION ad_conversion_deliveries_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.status IS DISTINCT FROM OLD.status
        AND NOT (
            (OLD.status = 'pending' AND NEW.status = 'claimed')
            OR (OLD.status = 'claimed' AND NEW.status IN ('sent', 'skipped', 'failed'))
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

CREATE TRIGGER ad_conversion_deliveries_guard
BEFORE UPDATE ON ad_conversion_deliveries
FOR EACH ROW
EXECUTE FUNCTION ad_conversion_deliveries_guard();
SQL);
    }

    private function createSqliteChecks(): void
    {
        DB::statement(<<<'SQL'
CREATE TRIGGER ace_value_has_currency_insert
BEFORE INSERT ON ad_conversion_events
FOR EACH ROW
WHEN NOT ((NEW.value IS NULL) = (NEW.currency IS NULL))
BEGIN
    SELECT RAISE(ABORT, 'ace_value_has_currency');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ace_value_has_currency_update
BEFORE UPDATE ON ad_conversion_events
FOR EACH ROW
WHEN NOT ((NEW.value IS NULL) = (NEW.currency IS NULL))
BEGIN
    SELECT RAISE(ABORT, 'ace_value_has_currency');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER acm_value_only_move_in_insert
BEFORE INSERT ON ad_conversion_mappings
FOR EACH ROW
WHEN NOT (NEW.value_rule = 'none' OR NEW.event_key = 'move_in')
BEGIN
    SELECT RAISE(ABORT, 'acm_value_only_move_in');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER acm_value_only_move_in_update
BEFORE UPDATE ON ad_conversion_mappings
FOR EACH ROW
WHEN NOT (NEW.value_rule = 'none' OR NEW.event_key = 'move_in')
BEGIN
    SELECT RAISE(ABORT, 'acm_value_only_move_in');
END
SQL);
    }

    private function createSqliteTriggers(): void
    {
        $changed = implode(' OR ', [
            'NEW.id IS NOT OLD.id',
            'NEW.visitor_key IS NOT OLD.visitor_key',
            'NEW.occurred_at IS NOT OLD.occurred_at',
            'NEW.site_id IS NOT OLD.site_id',
            'NEW.platform IS NOT OLD.platform',
            'NEW.utm_source IS NOT OLD.utm_source',
            'NEW.utm_medium IS NOT OLD.utm_medium',
            'NEW.utm_campaign IS NOT OLD.utm_campaign',
            'NEW.signup_form_id IS NOT OLD.signup_form_id',
            'NEW.consent_snapshot IS NOT OLD.consent_snapshot',
            'NEW.created_at IS NOT OLD.created_at',
            '(NEW.contact_id IS NOT OLD.contact_id AND NOT (OLD.contact_id IS NULL AND NEW.contact_id IS NOT NULL))',
        ]);

        DB::statement("
            CREATE TRIGGER marketing_touches_reject_update
            BEFORE UPDATE ON marketing_touches
            FOR EACH ROW
            WHEN {$changed}
            BEGIN
                SELECT RAISE(ABORT, 'marketing_touch_immutable');
            END
        ");

        DB::statement(<<<'SQL'
CREATE TRIGGER marketing_touches_reject_delete
BEFORE DELETE ON marketing_touches
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'marketing_touch_immutable');
END
SQL);

        $eventChanged = implode(' OR ', [
            'NEW.id IS NOT OLD.id',
            'NEW.event_key IS NOT OLD.event_key',
            'NEW.dedupe_id IS NOT OLD.dedupe_id',
            'NEW.contact_id IS NOT OLD.contact_id',
            'NEW.subject_type IS NOT OLD.subject_type',
            'NEW.subject_id IS NOT OLD.subject_id',
            'NEW.site_id IS NOT OLD.site_id',
            'NEW.value IS NOT OLD.value',
            'NEW.currency IS NOT OLD.currency',
            'NEW.occurred_at IS NOT OLD.occurred_at',
            'NEW.created_at IS NOT OLD.created_at',
        ]);

        DB::statement("
            CREATE TRIGGER ad_conversion_events_reject_update
            BEFORE UPDATE ON ad_conversion_events
            FOR EACH ROW
            WHEN {$eventChanged}
            BEGIN
                SELECT RAISE(ABORT, 'ad_conversion_event_immutable');
            END
        ");

        DB::statement(<<<'SQL'
CREATE TRIGGER ad_conversion_events_reject_delete
BEFORE DELETE ON ad_conversion_events
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'ad_conversion_event_immutable');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ad_conversion_deliveries_guard_update
BEFORE UPDATE ON ad_conversion_deliveries
FOR EACH ROW
WHEN (
    (
        NEW.status IS NOT OLD.status
        AND NOT (
            (OLD.status = 'pending' AND NEW.status = 'claimed')
            OR (OLD.status = 'claimed' AND NEW.status IN ('sent', 'skipped', 'failed'))
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
                OR (OLD.status = 'failed' AND NEW.status = 'pending')
            )
        THEN RAISE(ABORT, 'ad_conversion_delivery_status')
        ELSE RAISE(ABORT, 'ad_conversion_delivery_immutable')
    END;
END
SQL);
    }
};
