<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('labels');
            $table->json('descriptions')->nullable();
            $table->string('kind', 16);
            $table->json('channels');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->boolean('is_public')->default(true);
            $table->boolean('requires_double_opt_in')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('consent_texts', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->unsignedInteger('version');
            $table->string('locale', 5);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['key', 'version', 'locale']);
        });

        Schema::create('consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->foreignId('subscription_list_id')->constrained('subscription_lists')->restrictOnDelete();
            $table->string('action', 16);
            $table->string('source', 24);
            $table->foreignId('consent_text_id')->nullable()->constrained('consent_texts')->restrictOnDelete();
            $table->json('evidence')->nullable();
            $table->string('causer_type', 64)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
        });

        DB::statement('CREATE INDEX ce_state_idx ON consent_events (contact_id, channel, subscription_list_id, occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX ce_list_idx ON consent_events (subscription_list_id, channel, occurred_at DESC)');

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresTriggers();

            return;
        }

        $this->createSqliteTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS consent_events_reject_mutation ON consent_events');
            DB::statement('DROP FUNCTION IF EXISTS consent_events_reject_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS consent_texts_reject_mutation ON consent_texts');
            DB::statement('DROP FUNCTION IF EXISTS consent_texts_reject_mutation()');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS consent_events_reject_update');
            DB::statement('DROP TRIGGER IF EXISTS consent_events_reject_delete');
            DB::statement('DROP TRIGGER IF EXISTS consent_texts_reject_update');
            DB::statement('DROP TRIGGER IF EXISTS consent_texts_reject_delete');
        }

        Schema::dropIfExists('consent_events');
        Schema::dropIfExists('consent_texts');
        Schema::dropIfExists('subscription_lists');
    }

    private function createPostgresTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION consent_texts_reject_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'consent_text_immutable';
END;
$$;

CREATE TRIGGER consent_texts_reject_mutation
BEFORE UPDATE OR DELETE ON consent_texts
FOR EACH ROW
EXECUTE FUNCTION consent_texts_reject_mutation();

CREATE OR REPLACE FUNCTION consent_events_reject_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'consent_event_immutable';
    END IF;

    IF NEW.evidence IS NOT NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.contact_id IS DISTINCT FROM OLD.contact_id
        OR NEW.channel IS DISTINCT FROM OLD.channel
        OR NEW.subscription_list_id IS DISTINCT FROM OLD.subscription_list_id
        OR NEW.action IS DISTINCT FROM OLD.action
        OR NEW.source IS DISTINCT FROM OLD.source
        OR NEW.consent_text_id IS DISTINCT FROM OLD.consent_text_id
        OR NEW.causer_type IS DISTINCT FROM OLD.causer_type
        OR NEW.causer_id IS DISTINCT FROM OLD.causer_id
        OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'consent_event_immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER consent_events_reject_mutation
BEFORE UPDATE OR DELETE ON consent_events
FOR EACH ROW
EXECUTE FUNCTION consent_events_reject_mutation();
SQL);
    }

    private function createSqliteTriggers(): void
    {
        DB::statement(<<<'SQL'
CREATE TRIGGER consent_texts_reject_update
BEFORE UPDATE ON consent_texts
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'consent_text_immutable');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER consent_texts_reject_delete
BEFORE DELETE ON consent_texts
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'consent_text_immutable');
END
SQL);

        $changed = implode(' OR ', [
            'NEW.evidence IS NOT NULL',
            'NEW.id IS NOT OLD.id',
            'NEW.contact_id IS NOT OLD.contact_id',
            'NEW.channel IS NOT OLD.channel',
            'NEW.subscription_list_id IS NOT OLD.subscription_list_id',
            'NEW.action IS NOT OLD.action',
            'NEW.source IS NOT OLD.source',
            'NEW.consent_text_id IS NOT OLD.consent_text_id',
            'NEW.causer_type IS NOT OLD.causer_type',
            'NEW.causer_id IS NOT OLD.causer_id',
            'NEW.occurred_at IS NOT OLD.occurred_at',
            'NEW.created_at IS NOT OLD.created_at',
        ]);

        DB::statement("
            CREATE TRIGGER consent_events_reject_update
            BEFORE UPDATE ON consent_events
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN {$changed}
                    THEN RAISE(ABORT, 'consent_event_immutable')
                END;
            END
        ");

        DB::statement(<<<'SQL'
CREATE TRIGGER consent_events_reject_delete
BEFORE DELETE ON consent_events
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'consent_event_immutable');
END
SQL);
    }
};
