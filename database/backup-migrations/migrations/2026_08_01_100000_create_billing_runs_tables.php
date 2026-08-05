<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observability for the recurring billing job (S05-01). Append-only in spirit:
 * runs finish, items are written once. No update/delete API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('trigger', 16);
            $table->date('horizon_date');
            $table->unsignedInteger('contracts_considered')->default(0);
            $table->unsignedInteger('contracts_billed')->default(0);
            $table->unsignedInteger('contracts_skipped')->default(0);
            $table->unsignedInteger('contracts_failed')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('billing_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_run_id')->constrained('billing_runs');
            $table->foreignId('contract_id')->constrained('contracts');
            $table->string('outcome', 16);
            $table->smallInteger('periods_billed')->default(0);
            $table->string('detail', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('invoice_ids')->nullable();
            $table->decimal('amount_total', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['billing_run_id', 'outcome'], 'bri_run_idx');
            $table->index('contract_id', 'bri_contract_idx');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['status', 'billed_through'], 'contracts_status_billed_through_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_status_billed_through_idx');
        });

        Schema::dropIfExists('billing_run_items');
        Schema::dropIfExists('billing_runs');
    }
};
