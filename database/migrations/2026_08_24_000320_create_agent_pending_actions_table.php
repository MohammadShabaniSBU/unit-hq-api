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
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgres();

            return;
        }

        $this->createSqlite();
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_pending_actions');
    }

    private function createPostgres(): void
    {
        Schema::create('agent_pending_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->foreignId('agent_tool_invocation_id')->unique()->constrained('agent_tool_invocations')->restrictOnDelete();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('tool_key');
            $table->json('payload');
            $table->json('preview')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('resolved_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['ai_agent_id', 'status', 'created_at']);
            $table->index('agent_conversation_id');
            $table->index('site_id');
        });

        DB::statement("ALTER TABLE agent_pending_actions ADD CONSTRAINT agent_pending_actions_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'expired', 'superseded'))");
        DB::statement("ALTER TABLE agent_pending_actions ADD CONSTRAINT agent_pending_actions_pending_unresolved CHECK (status <> 'pending' OR resolved_at IS NULL)");
        DB::statement("ALTER TABLE agent_pending_actions ADD CONSTRAINT agent_pending_actions_resolved_by CHECK (status NOT IN ('approved', 'rejected') OR resolved_by_employee_id IS NOT NULL)");
        DB::statement("ALTER TABLE agent_pending_actions ADD CONSTRAINT agent_pending_actions_approved_result CHECK (status <> 'approved' OR result_id IS NOT NULL OR failure_reason IS NOT NULL)");
    }

    private function createSqlite(): void
    {
        DB::statement("
            CREATE TABLE agent_pending_actions (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                agent_conversation_id INTEGER NOT NULL,
                agent_tool_invocation_id INTEGER NOT NULL,
                ai_agent_id INTEGER NOT NULL,
                site_id INTEGER NOT NULL,
                tool_key VARCHAR NOT NULL,
                payload TEXT NOT NULL,
                preview TEXT NULL,
                status VARCHAR NOT NULL DEFAULT 'pending',
                resolved_by_employee_id INTEGER NULL,
                resolved_at DATETIME NULL,
                rejection_reason TEXT NULL,
                result_type VARCHAR NULL,
                result_id INTEGER NULL,
                failure_reason TEXT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (agent_conversation_id) REFERENCES agent_conversations (id) ON DELETE CASCADE,
                FOREIGN KEY (agent_tool_invocation_id) REFERENCES agent_tool_invocations (id),
                FOREIGN KEY (ai_agent_id) REFERENCES ai_agents (id),
                FOREIGN KEY (site_id) REFERENCES sites (id),
                FOREIGN KEY (resolved_by_employee_id) REFERENCES employees (id),
                CHECK (status IN ('pending', 'approved', 'rejected', 'expired', 'superseded')),
                CHECK (status <> 'pending' OR resolved_at IS NULL),
                CHECK (status NOT IN ('approved', 'rejected') OR resolved_by_employee_id IS NOT NULL),
                CHECK (status <> 'approved' OR result_id IS NOT NULL OR failure_reason IS NOT NULL)
            )
        ");

        DB::statement('CREATE UNIQUE INDEX agent_pending_actions_agent_tool_invocation_id_unique ON agent_pending_actions (agent_tool_invocation_id)');
        DB::statement('CREATE INDEX agent_pending_actions_status_expires_at_index ON agent_pending_actions (status, expires_at)');
        DB::statement('CREATE INDEX agent_pending_actions_ai_agent_id_status_created_at_index ON agent_pending_actions (ai_agent_id, status, created_at)');
        DB::statement('CREATE INDEX agent_pending_actions_agent_conversation_id_index ON agent_pending_actions (agent_conversation_id)');
        DB::statement('CREATE INDEX agent_pending_actions_site_id_index ON agent_pending_actions (site_id)');
    }
};
