<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('channel', 255);
            $table->string('recipient_address', 255);
            $table->timestamp('sent_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_status', 255)->default('queued');
            $table->timestamp('created_at')->useCurrent();
            $table->string('provider_message_id', 255)->nullable();
            $table->foreignId('communication_account_id')->nullable()->constrained('communication_accounts')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->index(['offer_id', 'sent_at'], 'offer_deliveries_offer_id_sent_at_index');
            $table->index('provider_message_id', 'offer_deliveries_provider_message_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_deliveries');
    }
};
