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
            $table->string('tool_call_id')->nullable();
            $table->index(['agent_conversation_message_id', 'tool_call_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool_invocations', function (Blueprint $table): void {
            $table->dropIndex(['agent_conversation_message_id', 'tool_call_id']);
            $table->dropColumn('tool_call_id');
        });
    }
};
