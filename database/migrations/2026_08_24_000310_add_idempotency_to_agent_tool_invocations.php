<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tool_invocations', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable();
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('fact_keys')->nullable();
        });

        DB::statement(
            "CREATE UNIQUE INDEX agent_tool_invocations_idempotency_unique ON agent_tool_invocations (agent_conversation_id, idempotency_key) WHERE idempotency_key IS NOT NULL AND status = 'ok'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS agent_tool_invocations_idempotency_unique');

        Schema::table('agent_tool_invocations', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'result_type', 'result_id', 'fact_keys']);
        });
    }
};
