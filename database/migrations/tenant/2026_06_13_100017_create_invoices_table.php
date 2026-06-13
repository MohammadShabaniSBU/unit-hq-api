<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices group charges for a billing period. They are a view over charges,
 * not the atomic unit of money. The true paid/unpaid state is derived from
 * allocations, not from this status column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->string('status')->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('lease_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
