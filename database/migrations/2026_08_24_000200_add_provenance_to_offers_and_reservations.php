<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance on offers / reservations (S24-01).
 *
 * Constraint is the same predicate on both drivers:
 *   source <> 'ai_agent' OR ai_agent_id IS NOT NULL
 *
 * Drivers diverge because SQLite cannot ALTER TABLE ADD CONSTRAINT on an
 * existing table, and this repo does not rebuild live tables with inbound FKs
 * to attach a CHECK. Postgres gets a real CHECK. SQLite gets BEFORE INSERT
 * and BEFORE UPDATE triggers that RAISE(ABORT) under the same condition.
 * Do not "harmonise" this into a table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->string('source', 32)->default('operator');
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->restrictOnDelete();
            $table->index(['source', 'created_at'], 'offers_source_created_at_index');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('source', 32)->default('operator');
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->restrictOnDelete();
            $table->index(['source', 'created_at'], 'reservations_source_created_at_index');
            $table->index(['contact_id', 'status'], 'reservations_contact_id_status_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE offers ADD CONSTRAINT offers_ai_agent_source CHECK (source <> 'ai_agent' OR ai_agent_id IS NOT NULL)"
            );
            DB::statement(
                "ALTER TABLE reservations ADD CONSTRAINT reservations_ai_agent_source CHECK (source <> 'ai_agent' OR ai_agent_id IS NOT NULL)"
            );

            return;
        }

        $this->createSqliteTrigger('offers');
        $this->createSqliteTrigger('reservations');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_ai_agent_source');
            DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_ai_agent_source');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS offers_ai_agent_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS offers_ai_agent_source_update');
            DB::statement('DROP TRIGGER IF EXISTS reservations_ai_agent_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS reservations_ai_agent_source_update');
        }

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropIndex('offers_source_created_at_index');
            $table->dropConstrainedForeignId('ai_agent_id');
            $table->dropColumn('source');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_source_created_at_index');
            $table->dropIndex('reservations_contact_id_status_index');
            $table->dropConstrainedForeignId('ai_agent_id');
            $table->dropColumn('source');
        });
    }

    private function createSqliteTrigger(string $table): void
    {
        $constraint = "{$table}_ai_agent_source";

        DB::statement("
            CREATE TRIGGER {$constraint}_insert
            BEFORE INSERT ON {$table}
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN NEW.source = 'ai_agent' AND NEW.ai_agent_id IS NULL
                    THEN RAISE(ABORT, '{$constraint}')
                END;
            END
        ");

        DB::statement("
            CREATE TRIGGER {$constraint}_update
            BEFORE UPDATE ON {$table}
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN NEW.source = 'ai_agent' AND NEW.ai_agent_id IS NULL
                    THEN RAISE(ABORT, '{$constraint}')
                END;
            END
        ");
    }
};
