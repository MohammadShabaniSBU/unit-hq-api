<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_session_turns', function (Blueprint $table): void {
            $table->unsignedInteger('latency_ms')->nullable()->after('agent_conversation_message_id');
            $table->boolean('redrafted')->default(false)->after('latency_ms');
            $table->boolean('budget_exceeded')->default(false)->after('redrafted');
            $table->string('handoff_reason')->nullable()->after('budget_exceeded');
        });
    }

    public function down(): void
    {
        Schema::table('voice_session_turns', function (Blueprint $table): void {
            $table->dropColumn(['latency_ms', 'redrafted', 'budget_exceeded', 'handoff_reason']);
        });
    }
};
