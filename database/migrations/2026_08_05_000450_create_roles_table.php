<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->string('label', 128);
            $table->text('description')->nullable();
            $table->string('scope_level', 16);
            $table->boolean('is_system')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique('key', 'roles_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
