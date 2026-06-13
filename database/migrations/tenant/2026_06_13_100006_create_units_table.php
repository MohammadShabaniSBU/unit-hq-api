<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('unit_class_id')->constrained('unit_classes');
            $table->string('unit_number');
            // Physical overrides — only populated when a unit differs from its class.
            // Billing and listings use class dimensions; surveys use these actuals.
            $table->decimal('actual_width', 8, 2)->nullable();
            $table->decimal('actual_depth', 8, 2)->nullable();
            $table->decimal('actual_height', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'unit_number']);
            $table->index('unit_class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
