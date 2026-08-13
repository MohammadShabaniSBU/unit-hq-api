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
        Schema::create('ai_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('summarizable_type');
            $table->unsignedBigInteger('summarizable_id');
            $table->string('status');
            $table->text('body')->nullable();
            $table->json('highlights')->nullable();
            $table->string('locale', 5);
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version', 32);
            $table->string('source_digest', 64)->nullable();
            $table->json('source_counts')->nullable();
            $table->foreignId('ai_usage_event_id')->nullable()->constrained('ai_usage_events')->nullOnDelete();
            $table->string('error_code')->nullable();
            $table->foreignId('requested_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestamps();

            $table->index(
                ['summarizable_type', 'summarizable_id', 'created_at'],
                'ai_summaries_subject_created_idx'
            );
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX ai_summaries_current_uidx ON ai_summaries USING btree (summarizable_type, summarizable_id) WHERE (superseded_at IS NULL AND (status)::text = 'succeeded')"
            );
            DB::statement(
                "CREATE UNIQUE INDEX ai_summaries_inflight_uidx ON ai_summaries USING btree (summarizable_type, summarizable_id) WHERE ((status)::text = ANY ((ARRAY['queued'::character varying, 'running'::character varying])::text[]))"
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX ai_summaries_current_uidx ON ai_summaries (summarizable_type, summarizable_id) WHERE superseded_at IS NULL AND status = 'succeeded'"
            );
            DB::statement(
                "CREATE UNIQUE INDEX ai_summaries_inflight_uidx ON ai_summaries (summarizable_type, summarizable_id) WHERE status IN ('queued', 'running')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
