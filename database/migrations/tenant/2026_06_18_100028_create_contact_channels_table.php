<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_channels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();

            $table->string('type');
            $table->string('value', 255);
            $table->string('label', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('opted_in')->default(false);

            $table->timestamps();

            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_channels');
    }
};
