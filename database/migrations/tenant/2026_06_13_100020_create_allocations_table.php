<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps payments to specific charges. Enables partial payments, lump payments
 * across invoices, and overpayment credit. A charge is fully paid when the
 * sum of its allocations equals its amount. Rows are append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained('charges')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('payment_id');
            $table->index('charge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
