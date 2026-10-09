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
        Schema::create('template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_family_id')->constrained('template_families')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->foreignId('based_on_version_id')->nullable()->constrained('template_versions');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique(['template_family_id', 'version_number'], 'tver_family_number_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE template_versions ADD CONSTRAINT tver_published_shape CHECK ((status = 'draft' AND published_at IS NULL) OR (status = 'published' AND published_at IS NOT NULL))");
            DB::statement("CREATE UNIQUE INDEX tver_one_draft_idx ON template_versions (template_family_id) WHERE status = 'draft'");
            DB::statement("CREATE INDEX tver_family_published_idx ON template_versions (template_family_id, version_number DESC) WHERE status = 'published'");
            $this->createPublishedImmutabilityTrigger();

            return;
        }

        $this->createSqliteCheck();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS tver_reject_published_mutation ON template_versions');
            DB::statement('DROP FUNCTION IF EXISTS tver_reject_published_mutation()');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS tver_published_shape_insert');
            DB::statement('DROP TRIGGER IF EXISTS tver_published_shape_update');
        }

        Schema::dropIfExists('template_versions');
    }

    private function createPublishedImmutabilityTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tver_reject_published_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status = 'published' THEN
        RAISE EXCEPTION 'published_template_immutable';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER tver_reject_published_mutation
BEFORE UPDATE OR DELETE ON template_versions
FOR EACH ROW
EXECUTE FUNCTION tver_reject_published_mutation();
SQL);
    }

    private function createSqliteCheck(): void
    {
        $predicate = "NOT ((NEW.status = 'draft' AND NEW.published_at IS NULL) OR (NEW.status = 'published' AND NEW.published_at IS NOT NULL))";

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $event) {
            DB::statement("
                CREATE TRIGGER tver_published_shape_{$name}
                BEFORE {$event} ON template_versions
                FOR EACH ROW
                BEGIN
                    SELECT CASE
                        WHEN {$predicate}
                        THEN RAISE(ABORT, 'tver_published_shape')
                    END;
                END
            ");
        }
    }
};
