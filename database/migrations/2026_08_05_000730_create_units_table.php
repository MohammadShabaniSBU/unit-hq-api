<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('unit_class_id')->constrained('unit_classes');
            $table->string('unit_number', 255);
            $table->decimal('actual_width', 8, 2)->nullable();
            $table->decimal('actual_depth', 8, 2)->nullable();
            $table->decimal('actual_height', 8, 2)->nullable();
            $table->text('note')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'unit_number'], 'units_site_id_unit_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
