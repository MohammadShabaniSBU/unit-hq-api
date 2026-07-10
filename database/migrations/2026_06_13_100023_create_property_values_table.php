<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the value of a property_definition for a specific entity instance.
 * value is always stored as text and cast to the correct type at read time
 * using property_definitions.data_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_definition_id')->constrained('property_definitions')->cascadeOnDelete();
            $table->string('propertable_type');
            $table->unsignedBigInteger('propertable_id');
            $table->text('value');
            $table->timestamps();

            $table->index(['propertable_type', 'propertable_id']);
            $table->unique(['property_definition_id', 'propertable_type', 'propertable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_values');
    }
};
