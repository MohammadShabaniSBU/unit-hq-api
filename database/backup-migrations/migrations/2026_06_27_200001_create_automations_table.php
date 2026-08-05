<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();

            $table->index('enabled');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
