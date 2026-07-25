<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->foreignId('value_option_id')->nullable()->constrained('attribute_options')->nullOnDelete();
            $table->timestamps();

            $table->unique(['definition_id', 'entity_id']);
            $table->index(['entity_id', 'definition_id']);
            $table->index(['definition_id', 'value_number']);
            $table->index(['definition_id', 'value_date']);
            $table->index(['definition_id', 'value_boolean']);
            $table->index(['definition_id', 'value_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};
