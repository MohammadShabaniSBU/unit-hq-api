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
        Schema::table('template_versions', function (Blueprint $table): void {
            $table->unique(['id', 'template_family_id'], 'tver_id_family_idx');
        });

        Schema::table('template_variants', function (Blueprint $table): void {
            $table->foreign(['template_version_id', 'template_family_id'], 'tv_version_family_fk')
                ->references(['id', 'template_family_id'])
                ->on('template_versions')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->installPublishedVariantTrigger();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->restoreUpdateDeleteTrigger();
        }

        Schema::table('template_variants', function (Blueprint $table): void {
            $table->dropForeign('tv_version_family_fk');
        });

        Schema::table('template_versions', function (Blueprint $table): void {
            $table->dropUnique('tver_id_family_idx');
        });
    }

    private function installPublishedVariantTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tv_reject_published_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_status text;
    new_status text;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.template_version_id IS DISTINCT FROM NEW.template_version_id
           OR OLD.template_family_id IS DISTINCT FROM NEW.template_family_id THEN
            RAISE EXCEPTION 'published_template_immutable';
        END IF;
    END IF;

    IF TG_OP = 'UPDATE' OR TG_OP = 'DELETE' THEN
        SELECT status INTO old_status
        FROM template_versions
        WHERE id = OLD.template_version_id;

        IF old_status = 'published' THEN
            RAISE EXCEPTION 'published_template_immutable';
        END IF;
    END IF;

    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        SELECT status INTO new_status
        FROM template_versions
        WHERE id = NEW.template_version_id;

        IF new_status = 'published' THEN
            RAISE EXCEPTION 'published_template_immutable';
        END IF;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS tv_reject_published_mutation ON template_variants;

CREATE TRIGGER tv_reject_published_mutation
BEFORE INSERT OR UPDATE OR DELETE ON template_variants
FOR EACH ROW
EXECUTE FUNCTION tv_reject_published_mutation();
SQL);
    }

    private function restoreUpdateDeleteTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tv_reject_published_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    ver_status text;
BEGIN
    SELECT status INTO ver_status
    FROM template_versions
    WHERE id = OLD.template_version_id;

    IF ver_status = 'published' THEN
        RAISE EXCEPTION 'published_template_immutable';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS tv_reject_published_mutation ON template_variants;

CREATE TRIGGER tv_reject_published_mutation
BEFORE UPDATE OR DELETE ON template_variants
FOR EACH ROW
EXECUTE FUNCTION tv_reject_published_mutation();
SQL);
    }
};
