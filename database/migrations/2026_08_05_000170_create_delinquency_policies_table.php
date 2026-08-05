<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delinquency_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->boolean('auto_release_overlock')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->boolean('auto_restore_access')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_policies');
    }
};
