<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tool_invocations', function (Blueprint $table): void {
            $table->unsignedInteger('turn')->nullable();
            $table->unsignedInteger('seq')->nullable();
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 64)->nullable();
        });

        Schema::table('agent_handoffs', function (Blueprint $table): void {
            $table->foreignId('agent_conversation_message_id')->nullable()->constrained('agent_conversation_messages')->nullOnDelete();
            $table->unsignedInteger('turn')->nullable();
            $table->unsignedInteger('seq')->nullable();
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 64)->nullable();
        });

        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_conversation_message_id')->nullable();
            $table->unsignedInteger('turn')->nullable();
            $table->unsignedInteger('seq')->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->index('agent_conversation_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool_invocations', function (Blueprint $table): void {
            $table->dropColumn(['turn', 'seq', 'model', 'prompt_version']);
        });

        Schema::table('agent_handoffs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_conversation_message_id');
            $table->dropColumn(['turn', 'seq', 'model', 'prompt_version']);
        });

        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->dropColumn(['agent_conversation_message_id', 'turn', 'seq', 'prompt_version']);
        });
    }
};
