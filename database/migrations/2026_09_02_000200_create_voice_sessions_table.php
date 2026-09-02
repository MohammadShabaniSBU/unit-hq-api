<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('bridge_session_id')->unique();
            $table->foreignId('agent_conversation_id')->constrained('agent_conversations')->restrictOnDelete();
            $table->foreignId('voice_bridge_token_id')->constrained('voice_bridge_tokens');
            $table->string('caller_number', 32)->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts');
            $table->foreignId('site_id')->constrained('sites');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
    }
};
