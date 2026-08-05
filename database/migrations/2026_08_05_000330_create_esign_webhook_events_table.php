<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('esign_provider_account_id')->constrained('esign_provider_accounts')->cascadeOnDelete();
            $table->string('provider_event_id', 255);
            $table->json('payload');
            $table->string('processing_status', 16)->default('pending');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unique(['esign_provider_account_id', 'provider_event_id'], 'esign_webhook_events_account_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_webhook_events');
    }
};
