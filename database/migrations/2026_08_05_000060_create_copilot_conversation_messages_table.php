<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_conversation_messages', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36);
            $table->string('participant_type', 255)->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('agent', 255);
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->text('approval_state')->nullable();
            $table->timestamps();
            $table->foreign('conversation_id')->references('id')->on('copilot_conversations');
            $table->index('conversation_id', 'copilot_conversation_messages_conversation_id_index');
            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'copilot_conversation_messages_conversation_index');
            $table->index(['participant_type', 'participant_id'], 'copilot_conversation_messages_participant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_conversation_messages');
    }
};
