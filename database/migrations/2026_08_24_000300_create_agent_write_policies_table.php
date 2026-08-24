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
        Schema::dropIfExists('agent_write_policies');
    }

    private function createPostgres(): void
    {
        Schema::create('agent_write_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('tool_key');
            $table->string('mode');
            $table->integer('max_per_conversation')->nullable();
            $table->integer('max_per_day')->nullable();
            $table->string('min_verification')->nullable();
            $table->foreignId('updated_by_employee_id')->nullable()->constrained('employees');
            $table->timestamps();

            $table->unique(['ai_agent_id', 'tool_key']);
            $table->index('ai_agent_id');
        });

        DB::statement("ALTER TABLE agent_write_policies ADD CONSTRAINT agent_write_policies_mode_check CHECK (mode IN ('off', 'propose', 'commit'))");
        DB::statement("ALTER TABLE agent_write_policies ADD CONSTRAINT agent_write_policies_min_verification_check CHECK (min_verification IS NULL OR min_verification IN ('anonymous', 'channel_asserted', 'verified'))");
    }

    private function createSqlite(): void
    {
        DB::statement("
            CREATE TABLE agent_write_policies (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                ai_agent_id INTEGER NOT NULL,
                tool_key VARCHAR NOT NULL,
                mode VARCHAR NOT NULL,
                max_per_conversation INTEGER NULL,
                max_per_day INTEGER NULL,
                min_verification VARCHAR NULL,
                updated_by_employee_id INTEGER NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (ai_agent_id) REFERENCES ai_agents (id) ON DELETE CASCADE,
                FOREIGN KEY (updated_by_employee_id) REFERENCES employees (id),
                CHECK (mode IN ('off', 'propose', 'commit')),
                CHECK (min_verification IS NULL OR min_verification IN ('anonymous', 'channel_asserted', 'verified'))
            )
        ");

        DB::statement('CREATE UNIQUE INDEX agent_write_policies_ai_agent_id_tool_key_unique ON agent_write_policies (ai_agent_id, tool_key)');
        DB::statement('CREATE INDEX agent_write_policies_ai_agent_id_index ON agent_write_policies (ai_agent_id)');
    }
};
