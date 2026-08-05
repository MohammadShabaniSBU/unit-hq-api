<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Junction table: a unit class can have a different price per site.
 * A rate change means inserting a new prices row, closing the old one,
 * and inserting a new unit_class_rates row — never updating in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_class_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_class_id')->constrained('unit_classes')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('price_id')->constrained('prices');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['unit_class_id', 'site_id', 'price_id']);
            $table->index(['unit_class_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_class_rates');
    }
};
