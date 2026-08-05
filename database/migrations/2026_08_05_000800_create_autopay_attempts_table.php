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
        Schema::create('autopay_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->json('charge_ids');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('stripe_payment_intent_id', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('failure_code', 64)->nullable();
            $table->string('decline_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->string('triggered_by', 16);
            $table->foreignId('billing_run_id')->nullable()->constrained('billing_runs')->nullOnDelete();
            $table->timestampTz('attempted_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['contract_id', 'status'], 'aa_contract_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX aa_open_idx ON autopay_attempts USING btree (contract_id) WHERE ((status)::text = \'pending\'::text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('autopay_attempts');
    }
};
