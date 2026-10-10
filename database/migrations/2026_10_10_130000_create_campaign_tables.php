<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns, sends, and recipients (S31-00).
 *
 * Shape checks are the same predicates on both drivers:
 *   type = 'announcement' OR subscription_list_id IS NOT NULL
 *   type <> 'announcement' OR (subscription_list_id IS NULL AND site_ids is a non-empty array)
 *   type = 'announcement' OR ignore_send_window = false
 *
 * Postgres gets real CHECK constraints and uses jsonb_array_length.
 * SQLite has json_array_length, not jsonb_array_length, so it gets BEFORE
 * INSERT and BEFORE UPDATE triggers that RAISE(ABORT) under the same
 * conditions. Do not rebuild the table to "harmonise" them.
 *
 * Audience and pinned-send immutability triggers are Postgres-only.
 * Eloquent updating guards cover every driver.
 *
 * Audience columns may change while OLD.status is draft or pending_approval,
 * including the update that leaves those statuses. After that they are frozen.
 * A pinned send may change template_version_id only while the campaign is
 * pending_approval (clear it, or point at another published version of the
 * same family). Family, channel, and subject stay fixed once pinned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16);
            $table->string('name', 160);
            $table->string('status', 20)->default('draft');
            $table->foreignId('subscription_list_id')->nullable()->constrained('subscription_lists')->restrictOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('segments')->restrictOnDelete();
            $table->jsonb('site_ids')->default('[]');
            $table->jsonb('audience_snapshot')->nullable();
            $table->integer('estimated_recipients')->nullable();
            $table->integer('newsletter_issue_number')->nullable();
            $table->boolean('ignore_send_window')->default(false);
            $table->boolean('approval_required')->default(false);
            $table->foreignId('submitted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('duplicated_from_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'type'], 'campaigns_status_idx');
        });

        Schema::create('campaign_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->foreignId('template_family_id')->constrained('template_families')->restrictOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained('template_versions')->restrictOnDelete();
            $table->string('subject_override', 500)->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('materialised_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['campaign_id', 'channel']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_send_id')->constrained('campaign_sends')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('address', 255)->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('skip_reason', 32)->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampsTz();
            $table->unique(['campaign_send_id', 'contact_id']);
            $table->index(['campaign_send_id', 'status'], 'cr_send_status_idx');
        });

        DB::statement("CREATE INDEX cr_contact_sent_idx ON campaign_recipients (contact_id, processed_at) WHERE status = 'sent'");

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresChecks();
            $this->createPostgresTriggers();

            return;
        }

        $this->createSqliteChecks();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS campaigns_reject_audience_mutation ON campaigns');
            DB::statement('DROP FUNCTION IF EXISTS campaigns_reject_audience_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS campaign_sends_reject_pinned_mutation ON campaign_sends');
            DB::statement('DROP FUNCTION IF EXISTS campaign_sends_reject_pinned_mutation()');
        } else {
            foreach (['campaigns_list_required', 'campaigns_announcement_shape', 'campaigns_window_only_announcement'] as $name) {
                DB::statement("DROP TRIGGER IF EXISTS {$name}_insert");
                DB::statement("DROP TRIGGER IF EXISTS {$name}_update");
            }
        }

        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaign_sends');
        Schema::dropIfExists('campaigns');
    }

    private function createPostgresChecks(): void
    {
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_list_required CHECK (type = 'announcement' OR subscription_list_id IS NOT NULL)");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_announcement_shape CHECK (type <> 'announcement' OR (subscription_list_id IS NULL AND jsonb_array_length(site_ids) > 0))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_window_only_announcement CHECK (type = \'announcement\' OR ignore_send_window = FALSE)');
    }

    private function createPostgresTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION campaigns_reject_audience_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status NOT IN ('draft', 'pending_approval') AND (
        NEW.type IS DISTINCT FROM OLD.type
        OR NEW.subscription_list_id IS DISTINCT FROM OLD.subscription_list_id
        OR NEW.segment_id IS DISTINCT FROM OLD.segment_id
        OR NEW.site_ids IS DISTINCT FROM OLD.site_ids
        OR NEW.audience_snapshot IS DISTINCT FROM OLD.audience_snapshot
        OR NEW.ignore_send_window IS DISTINCT FROM OLD.ignore_send_window
    ) THEN
        RAISE EXCEPTION 'campaign_audience_immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER campaigns_reject_audience_mutation
BEFORE UPDATE ON campaigns
FOR EACH ROW
EXECUTE FUNCTION campaigns_reject_audience_mutation();

CREATE OR REPLACE FUNCTION campaign_sends_reject_pinned_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    camp_status varchar(20);
BEGIN
    IF OLD.template_version_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF NEW.template_family_id IS DISTINCT FROM OLD.template_family_id
        OR NEW.channel IS DISTINCT FROM OLD.channel
        OR NEW.subject_override IS DISTINCT FROM OLD.subject_override
    THEN
        RAISE EXCEPTION 'campaign_send_pinned';
    END IF;

    IF NEW.template_version_id IS NOT DISTINCT FROM OLD.template_version_id THEN
        RETURN NEW;
    END IF;

    SELECT status INTO camp_status FROM campaigns WHERE id = OLD.campaign_id;

    IF camp_status IS DISTINCT FROM 'pending_approval' THEN
        RAISE EXCEPTION 'campaign_send_pinned';
    END IF;

    IF NEW.template_version_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM template_versions
        WHERE id = NEW.template_version_id
          AND template_family_id = OLD.template_family_id
          AND status = 'published'
    ) THEN
        RAISE EXCEPTION 'campaign_send_pinned';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER campaign_sends_reject_pinned_mutation
BEFORE UPDATE ON campaign_sends
FOR EACH ROW
EXECUTE FUNCTION campaign_sends_reject_pinned_mutation();
SQL);
    }

    private function createSqliteChecks(): void
    {
        $this->createSqliteCheck(
            'campaigns_list_required',
            "NEW.type <> 'announcement' AND NEW.subscription_list_id IS NULL",
        );
        $this->createSqliteCheck(
            'campaigns_announcement_shape',
            "NEW.type = 'announcement' AND (NEW.subscription_list_id IS NOT NULL OR COALESCE(json_array_length(NEW.site_ids), 0) <= 0)",
        );
        $this->createSqliteCheck(
            'campaigns_window_only_announcement',
            "NEW.type <> 'announcement' AND NEW.ignore_send_window <> 0",
        );
    }

    private function createSqliteCheck(string $name, string $predicate): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $eventName => $event) {
            DB::statement("
                CREATE TRIGGER {$name}_{$eventName}
                BEFORE {$event} ON campaigns
                FOR EACH ROW
                BEGIN
                    SELECT CASE
                        WHEN {$predicate}
                        THEN RAISE(ABORT, '{$name}')
                    END;
                END
            ");
        }
    }
};
