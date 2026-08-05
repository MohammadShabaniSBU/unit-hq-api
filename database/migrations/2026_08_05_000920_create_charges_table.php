<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('billing_period_id')->nullable()->constrained('billing_periods')->nullOnDelete();
            $table->string('charge_type', 255);
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->text('description')->nullable();
            $table->foreignId('reversal_of_charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('net_amount', 10, 2)->nullable();
            $table->decimal('tax_rate_snapshot', 5, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->default('0');
            $table->char('currency', 3)->default('EUR');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->index(['contract_id', 'due_date'], 'charges_contract_id_due_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
