<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_invocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->foreignId('agent_conversation_message_id')->nullable()->constrained('agent_conversation_messages');
            $table->string('tool_key');
            $table->json('arguments');
            $table->json('result')->nullable();
            $table->text('result_summary')->nullable();
            $table->string('status');
            $table->string('denied_reason')->nullable();
            $table->string('required_verification')->nullable();
            $table->string('principal_verification')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['agent_conversation_id', 'created_at']);
            $table->index(['tool_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_invocations');
    }
};
