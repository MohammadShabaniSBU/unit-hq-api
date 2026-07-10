<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only debit entries. Rows are never updated or deleted.
 * Corrections are made by inserting an opposing row with reversal_of_charge_id
 * pointing to the original. Overdue is calculated per charge from due_date,
 * not from a net balance sign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('charge_type');
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->text('description')->nullable();
            // Self-referential: points to the charge this row reverses.
            $table->foreignId('reversal_of_charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contract_id', 'due_date']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
