<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 255);
            $table->string('key', 255);
            $table->string('label', 255);
            $table->string('type', 255);
            $table->string('group_name', 255)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_promoted')->default(false);
            $table->integer('usage_count')->default(0);
            $table->string('promoted_column', 255)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('entity_type', 'attribute_definitions_entity_type_index');
            $table->unique(['entity_type', 'key'], 'attribute_definitions_entity_type_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
