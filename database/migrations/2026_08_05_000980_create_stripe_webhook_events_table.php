<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id', 255);
            $table->string('event_type', 255);
            $table->json('payload');
            $table->string('processing_status', 255)->default('pending');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('payment_provider_account_id')->nullable()->constrained('payment_provider_accounts')->nullOnDelete();
            $table->unique(['payment_provider_account_id', 'stripe_event_id'], 'stripe_webhook_events_account_event_unique');
            $table->index('event_type', 'stripe_webhook_events_event_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
