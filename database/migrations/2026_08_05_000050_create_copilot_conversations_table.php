<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_conversations', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('participant_type', 255)->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('title', 255);
            $table->json('site_scope_snapshot')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['participant_type', 'participant_id', 'updated_at'], 'copilot_conversations_participant_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_conversations');
    }
};
