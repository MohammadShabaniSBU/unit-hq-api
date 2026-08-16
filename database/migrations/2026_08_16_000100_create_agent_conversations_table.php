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
        Schema::dropIfExists('agent_conversations');
    }

    private function createPostgres(): void
    {
        Schema::create('agent_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->restrictOnDelete();
            $table->string('audience');
            $table->string('origin');
            $table->string('channel');
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->foreignId('created_by_employee_id')->nullable()->constrained('employees');
            $table->foreignId('contact_id')->nullable()->constrained('contacts');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->string('verification_level');
            $table->string('state')->default('active');
            $table->string('locale', 5)->nullable();
            $table->foreignId('message_thread_id')->nullable()->constrained('message_threads');
            $table->timestamp('last_turn_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['origin', 'created_at']);
            $table->index(['ai_agent_id', 'created_at']);
            $table->index('contact_id');
            $table->index('state');
        });

        DB::statement(
            "ALTER TABLE agent_conversations ADD CONSTRAINT agent_conversations_internal_principal CHECK (audience <> 'internal' OR (employee_id IS NOT NULL AND contact_id IS NULL))"
        );
        DB::statement(
            "ALTER TABLE agent_conversations ADD CONSTRAINT agent_conversations_customer_no_employee CHECK (audience <> 'customer' OR employee_id IS NULL)"
        );
        DB::statement(
            "ALTER TABLE agent_conversations ADD CONSTRAINT agent_conversations_verified_contact CHECK (verification_level <> 'verified' OR contact_id IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE agent_conversations ADD CONSTRAINT agent_conversations_demo_creator CHECK (origin <> 'demo' OR created_by_employee_id IS NOT NULL)"
        );
    }

    private function createSqlite(): void
    {
        DB::statement("
            CREATE TABLE agent_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                ai_agent_id INTEGER NOT NULL,
                audience VARCHAR NOT NULL,
                origin VARCHAR NOT NULL,
                channel VARCHAR NOT NULL,
                employee_id INTEGER NULL,
                created_by_employee_id INTEGER NULL,
                contact_id INTEGER NULL,
                site_id INTEGER NULL,
                verification_level VARCHAR NOT NULL,
                state VARCHAR NOT NULL DEFAULT 'active',
                locale VARCHAR(5) NULL,
                message_thread_id INTEGER NULL,
                last_turn_at DATETIME NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (ai_agent_id) REFERENCES ai_agents (id) ON DELETE RESTRICT,
                FOREIGN KEY (employee_id) REFERENCES employees (id),
                FOREIGN KEY (created_by_employee_id) REFERENCES employees (id),
                FOREIGN KEY (contact_id) REFERENCES contacts (id),
                FOREIGN KEY (site_id) REFERENCES sites (id),
                FOREIGN KEY (message_thread_id) REFERENCES message_threads (id),
                CHECK (audience <> 'internal' OR (employee_id IS NOT NULL AND contact_id IS NULL)),
                CHECK (audience <> 'customer' OR employee_id IS NULL),
                CHECK (verification_level <> 'verified' OR contact_id IS NOT NULL),
                CHECK (origin <> 'demo' OR created_by_employee_id IS NOT NULL)
            )
        ");

        DB::statement('CREATE INDEX agent_conversations_origin_created_at_index ON agent_conversations (origin, created_at)');
        DB::statement('CREATE INDEX agent_conversations_ai_agent_id_created_at_index ON agent_conversations (ai_agent_id, created_at)');
        DB::statement('CREATE INDEX agent_conversations_contact_id_index ON agent_conversations (contact_id)');
        DB::statement('CREATE INDEX agent_conversations_state_index ON agent_conversations (state)');
    }
};
