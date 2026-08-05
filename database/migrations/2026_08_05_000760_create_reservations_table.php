<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('price_id')->nullable()->constrained('prices')->nullOnDelete();
            $table->string('status', 255)->default('pending');
            $table->foreignId('offer_option_id')->nullable()->constrained('offer_options');
            $table->timestamp('expires_at');
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->timestamps();
            $table->index('expires_at', 'reservations_expires_at_index');
            $table->index('status', 'reservations_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
