<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->string('idempotency_key', 255);
            $table->foreignId('reversal_of_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->char('currency', 3)->default('EUR');
            $table->string('method', 255)->nullable();
            $table->date('received_on')->nullable();
            $table->string('reference', 255)->nullable();
            $table->unique('idempotency_key', 'payments_idempotency_key_unique');
            $table->index('stripe_payment_intent_id', 'payments_stripe_payment_intent_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
