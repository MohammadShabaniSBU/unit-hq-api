<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts');
            $table->string('outcome', 16);
            $table->decimal('deposit_amount', 10, 2);
            $table->decimal('refunded_amount', 10, 2);
            $table->char('currency', 3);
            $table->string('payout_status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique('contract_id', 'deposit_settlements_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_settlements');
    }
};
