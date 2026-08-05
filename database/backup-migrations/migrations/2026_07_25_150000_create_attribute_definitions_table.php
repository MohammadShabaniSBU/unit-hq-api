<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->string('key');
            $table->string('label');
            $table->string('type');
            $table->string('group_name')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_promoted')->default(false);
            $table->unsignedInteger('usage_count')->default(0);
            $table->string('promoted_column')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
