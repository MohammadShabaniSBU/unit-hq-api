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
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgres();
        } else {
            $this->createSqlite();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }

    private function createPostgres(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('call_id');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->unsignedBigInteger('agent_conversation_id')->nullable();
            $table->string('conversation_id', 64)->nullable();
            $table->string('purpose', 32);
            $table->string('provider', 24)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('status', 16);
            $table->integer('input_tokens')->default(0);
            $table->integer('cached_input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('reasoning_tokens')->default(0);
            $table->boolean('tokens_estimated')->default(false);
            $table->smallInteger('tool_calls')->default('0');
            $table->integer('duration_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('raw_usage')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique('call_id', 'ai_usage_call_idx');
            $table->index(['employee_id', 'started_at'], 'ai_usage_employee_idx');
            $table->index(['model', 'started_at'], 'ai_usage_model_idx');
            $table->index('agent_conversation_id', 'ai_usage_agent_conversation_idx');
        });

        DB::statement(
            'ALTER TABLE ai_usage_events ADD CONSTRAINT ai_usage_events_actor_present CHECK (employee_id IS NOT NULL OR ai_agent_id IS NOT NULL)'
        );
        DB::statement('CREATE INDEX ai_usage_open_idx ON ai_usage_events USING btree (started_at) WHERE (settled_at IS NULL)');
    }

    private function createSqlite(): void
    {
        DB::statement('
            CREATE TABLE ai_usage_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                call_id VARCHAR NOT NULL,
                employee_id INTEGER NULL,
                ai_agent_id INTEGER NULL,
                agent_conversation_id INTEGER NULL,
                conversation_id VARCHAR(64) NULL,
                purpose VARCHAR(32) NOT NULL,
                provider VARCHAR(24) NULL,
                model VARCHAR(128) NULL,
                status VARCHAR(16) NOT NULL,
                input_tokens INTEGER NOT NULL DEFAULT 0,
                cached_input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                reasoning_tokens INTEGER NOT NULL DEFAULT 0,
                tokens_estimated TINYINT(1) NOT NULL DEFAULT 0,
                tool_calls INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NULL,
                request_id VARCHAR(64) NULL,
                raw_usage TEXT NULL,
                started_at DATETIME NOT NULL,
                settled_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL,
                FOREIGN KEY (ai_agent_id) REFERENCES ai_agents (id) ON DELETE SET NULL,
                CHECK (employee_id IS NOT NULL OR ai_agent_id IS NOT NULL)
            )
        ');

        DB::statement('CREATE UNIQUE INDEX ai_usage_call_idx ON ai_usage_events (call_id)');
        DB::statement('CREATE INDEX ai_usage_employee_idx ON ai_usage_events (employee_id, started_at)');
        DB::statement('CREATE INDEX ai_usage_model_idx ON ai_usage_events (model, started_at)');
        DB::statement('CREATE INDEX ai_usage_agent_conversation_idx ON ai_usage_events (agent_conversation_id)');
    }
};
