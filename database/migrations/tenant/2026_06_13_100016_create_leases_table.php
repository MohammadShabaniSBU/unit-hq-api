<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational and billing anchor. Every charge, payment, and allocation
 * references a lease. reservation_id is nullable to allow walk-in leases
 * that bypass the offer/reservation pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('actual_rate', 10, 2);
            $table->decimal('actual_insurance', 10, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index(['unit_id', 'status']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
