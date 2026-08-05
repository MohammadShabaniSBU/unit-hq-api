<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S07-01 fact tables: delinquencies, delinquency_steps, contract_notices.
 * Partial unique indexes require PostgreSQL — skipped on SQLite (app-layer guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->smallInteger('rate_change_notice_days')->nullable()->after('notice_period_days');
        });

        Schema::create('contract_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('notice_type', 32);
            $table->date('effective_date')->nullable();
            $table->date('required_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_channel', 24)->nullable();
            $table->string('sent_to', 255)->nullable();
            $table->string('document_ref', 255)->nullable();
            $table->text('short_notice_reason')->nullable();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'notice_type'], 'contract_notices_contract_idx');
        });

        Schema::create('delinquencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('delinquency_policy_id')->constrained('delinquency_policies');
            $table->date('anchor_due_date');
            $table->date('opened_on');
            $table->date('cured_on')->nullable();
            $table->string('cure_trigger', 24)->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->text('paused_reason')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'opened_on'], 'delinquencies_contract_idx');
        });

        Schema::create('delinquency_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delinquency_id')->constrained('delinquencies')->cascadeOnDelete();
            $table->foreignId('policy_step_id')->nullable()->constrained('delinquency_policy_steps')->nullOnDelete();
            $table->string('action', 32);
            $table->date('executed_on');
            $table->string('trigger', 16);
            $table->foreignId('charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->foreignId('unit_hold_id')->nullable()->constrained('unit_holds')->nullOnDelete();
            $table->foreignId('contract_notice_id')->nullable()->constrained('contract_notices')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('detail')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX delinquencies_open_idx '
                .'ON delinquencies (contract_id) WHERE cured_on IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX ds_once_idx '
                .'ON delinquency_steps (delinquency_id, policy_step_id) '
                .'WHERE policy_step_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_steps');
        Schema::dropIfExists('delinquencies');
        Schema::dropIfExists('contract_notices');

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('rate_change_notice_days');
        });
    }
};
