<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per send event. Kept separate from offers so that an offer can be
 * resent across multiple channels or to a corrected address without touching
 * the offer record itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('channel');
            $table->string('recipient_address');
            $table->timestamp('sent_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_status')->default('queued');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['offer_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_deliveries');
    }
};
