<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_bridge_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 40)->unique();
            $table->text('secret');
            $table->text('secret_previous')->nullable();
            $table->foreignId('site_id')->constrained('sites');
            $table->string('label')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_bridge_tokens');
    }
};
