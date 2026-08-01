<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery-event idempotency store (Stripe-shaped) plus per-message status history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_account_id')->constrained('communication_accounts')->cascadeOnDelete();
            $table->string('provider_event_id', 255);
            $table->json('payload');
            $table->string('processing_status', 16)->default('pending');
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();

            $table->unique(
                ['communication_account_id', 'provider_event_id'],
                'comms_webhook_events_account_event_unique'
            );
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->json('delivery_events')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('delivery_events');
        });

        Schema::dropIfExists('comms_webhook_events');
    }
};
