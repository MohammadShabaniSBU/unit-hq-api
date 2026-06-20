<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory hold record. Always references a specific unit (never a class).
 * Created in a single transaction when a contact selects an offer option.
 * The offer does NOT hold a back-reference to reservation — the FK is
 * one-way: reservations → offer_options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->string('status')->default('pending');
            $table->foreignId('offer_option_id')->constrained('offer_options');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('unit_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
