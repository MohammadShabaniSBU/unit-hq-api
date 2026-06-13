<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only credit entries. Confirmed from Stripe webhooks using
 * idempotency_key — never optimistically from the client.
 * Reversals are made by inserting an opposing row with reversal_of_payment_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('idempotency_key')->unique();
            // Self-referential: points to the payment this row reverses.
            $table->foreignId('reversal_of_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('lease_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
