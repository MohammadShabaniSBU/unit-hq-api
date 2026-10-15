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
        Schema::create('template_assist_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_family_id')->constrained('template_families')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('conversation_id', 36)->unique();
            $table->foreign('conversation_id')->references('id')->on('copilot_conversations');
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestampTz('last_turn_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE INDEX tas_family_locale_employee_idx ON template_assist_sessions (template_family_id, locale, employee_id, last_turn_at DESC)');

        Schema::create('template_assist_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('template_assist_sessions')->cascadeOnDelete();
            $table->uuid('call_id')->unique();
            $table->string('client_message_id', 64);
            $table->foreignId('template_version_id')->constrained('template_versions')->restrictOnDelete();
            $table->json('selection')->nullable();
            $table->json('base_document');
            $table->char('base_hash', 64);
            $table->json('working_document');
            $table->json('ops')->default('[]');
            $table->json('subject_change')->nullable();
            $table->json('op_summary')->default('{}');
            $table->string('status', 16)->default('running');
            $table->string('error_key', 64)->nullable();
            $table->string('prompt_version', 32);
            $table->smallInteger('tool_call_count')->default(0);
            $table->timestampsTz();
            $table->unique(['session_id', 'client_message_id']);
            $table->index(['status', 'created_at'], 'tat_status_created_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresTrigger();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS tat_reject_content_mutation ON template_assist_turns');
            DB::statement('DROP FUNCTION IF EXISTS tat_reject_content_mutation()');
        }

        Schema::dropIfExists('template_assist_turns');
        Schema::dropIfExists('template_assist_sessions');
    }

    private function createPostgresTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tat_reject_content_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status = 'running' THEN
        RETURN NEW;
    END IF;

    -- json has no equality operator; compare as jsonb.
    IF NEW.base_document::jsonb IS DISTINCT FROM OLD.base_document::jsonb
        OR NEW.working_document::jsonb IS DISTINCT FROM OLD.working_document::jsonb
        OR NEW.ops::jsonb IS DISTINCT FROM OLD.ops::jsonb
        OR NEW.subject_change::jsonb IS DISTINCT FROM OLD.subject_change::jsonb
    THEN
        RAISE EXCEPTION 'template_assist_turn_content_frozen';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER tat_reject_content_mutation
BEFORE UPDATE ON template_assist_turns
FOR EACH ROW
EXECUTE FUNCTION tat_reject_content_mutation();
SQL);
    }
};
