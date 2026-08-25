<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_guardrail_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->foreignId('agent_conversation_message_id')->nullable()->constrained('agent_conversation_messages')->nullOnDelete();
            $table->unsignedInteger('turn');
            $table->unsignedInteger('seq');
            $table->string('guard', 64);
            $table->string('verdict', 24);
            $table->json('detail')->nullable();
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_conversation_id', 'seq']);
            $table->index('agent_conversation_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_guardrail_events');
    }
};
