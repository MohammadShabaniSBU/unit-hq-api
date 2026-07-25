<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_groups', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->string('key');
            $table->string('label');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['entity_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_groups');
    }
};
