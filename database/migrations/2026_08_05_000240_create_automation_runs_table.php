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
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->string('status', 50)->default('pending');
            $table->json('trigger_payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('trigger_node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->string('subject_type', 255)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type', 255)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->foreignId('root_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->smallInteger('depth')->default('0');
            $table->text('error')->nullable();
            $table->json('guard')->nullable();
            $table->string('cancel_cause', 32)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('waiting_until')->nullable();
            $table->foreignId('current_node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->string('active_key', 255)->nullable();
            $table->timestamps();
            $table->index(['automation_id', 'status'], 'automation_runs_automation_status_index');
            $table->index('started_at', 'automation_runs_started_at_index');
            $table->index('status', 'automation_runs_status_index');
            $table->index(['subject_type', 'subject_id'], 'automation_runs_subject_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ar_active_enrolment_idx ON automation_runs USING btree (automation_id, active_key) WHERE (active_key IS NOT NULL)');
            DB::statement('CREATE INDEX ar_waiting_idx ON automation_runs USING btree (waiting_until) WHERE ((status)::text = \'waiting\'::text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
