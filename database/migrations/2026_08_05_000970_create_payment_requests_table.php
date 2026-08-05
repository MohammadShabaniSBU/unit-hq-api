<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64);
            $table->foreignId('contract_id')->constrained('contracts');
            $table->foreignId('payment_provider_account_id')->constrained('payment_provider_accounts');
            $table->json('charge_ids');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->string('stripe_payment_intent_id', 64)->nullable();
            $table->boolean('save_card_requested')->default(false);
            $table->foreignId('paid_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique('token', 'pr_token_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
