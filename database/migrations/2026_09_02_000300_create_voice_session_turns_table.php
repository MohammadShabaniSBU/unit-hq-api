<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_session_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_session_id')->constrained('voice_sessions')->restrictOnDelete();
            $table->string('turn_id');
            $table->text('answer_text');
            $table->boolean('transfer')->default(false);
            $table->foreignId('agent_conversation_message_id')->nullable()->constrained('agent_conversation_messages');
            $table->timestamps();

            $table->unique(['voice_session_id', 'turn_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_session_turns');
    }
};
