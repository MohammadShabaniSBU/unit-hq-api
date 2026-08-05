<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_run_id')->constrained('billing_runs');
            $table->foreignId('contract_id')->constrained('contracts');
            $table->string('outcome', 16);
            $table->smallInteger('periods_billed')->default('0');
            $table->string('detail', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('invoice_ids')->nullable();
            $table->decimal('amount_total', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['billing_run_id', 'outcome'], 'bri_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_run_items');
    }
};
