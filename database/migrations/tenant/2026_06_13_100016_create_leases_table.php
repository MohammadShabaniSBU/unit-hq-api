<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational and billing anchor created at contract signing.
 * Every charge, payment, and allocation references a contract.
 * reservation_id is nullable to allow walk-in contracts that bypass the pipeline.
 *
 * ContractItems hold the polymorphic line-items (unit, insurance, etc.) with
 * their individual rates instead of flat FK columns on the contract itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index('contact_id');
            $table->index('status');
        });

        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->decimal('rate', 10, 2);
            $table->timestamps();

            $table->index(['item_type', 'item_id']);
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_items');
        Schema::dropIfExists('contracts');
    }
};
