<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->json('payload');
            $table->timestamps();
            $table->unique('name', 'settings_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
