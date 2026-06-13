<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_classes', function (Blueprint $table) {
            $table->id();
            $table->string('code_slug')->unique();
            $table->string('label');
            $table->string('tier');
            $table->decimal('width', 8, 2);
            $table->decimal('depth', 8, 2);
            $table->decimal('height', 8, 2);
            $table->json('amenities')->nullable();
            // Convenience pointer to the currently active price — authoritative
            // pricing history lives in unit_class_rates + prices.
            $table->foreignId('current_price_id')->nullable()->constrained('prices')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_classes');
    }
};
