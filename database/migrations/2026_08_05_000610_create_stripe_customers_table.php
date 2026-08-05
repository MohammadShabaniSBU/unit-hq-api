<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('payment_provider_account_id')->constrained('payment_provider_accounts')->cascadeOnDelete();
            $table->string('stripe_customer_id', 64);
            $table->timestamps();
            $table->unique(['contact_id', 'payment_provider_account_id'], 'sc_pair_idx');
            $table->unique('stripe_customer_id', 'stripe_customers_stripe_customer_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_customers');
    }
};
