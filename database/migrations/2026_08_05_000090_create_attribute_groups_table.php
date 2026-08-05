<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 255);
            $table->string('key', 255);
            $table->string('label', 255);
            $table->integer('display_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->index('entity_type', 'attribute_groups_entity_type_index');
            $table->unique(['entity_type', 'key'], 'attribute_groups_entity_type_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_groups');
    }
};
