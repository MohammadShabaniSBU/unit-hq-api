<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission', 64);
            $table->timestamps();
            $table->unique(['role_id', 'permission'], 'role_permissions_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
