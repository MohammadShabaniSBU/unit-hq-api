<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defines operator-configurable custom fields for any entity type.
 * entity_type holds the fully-qualified model class name (or a morph alias).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->string('key');
            $table->string('label');
            $table->string('data_type');
            // Allowed values for select-type fields.
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['entity_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_definitions');
    }
};
