<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->integer('sequence');
            $table->string('role');
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('model')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('finish_reason')->nullable();
            $table->string('blocked_by')->nullable();
            $table->foreignId('emitted_message_id')->nullable()->constrained('messages');
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_conversation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_messages');
    }
};
