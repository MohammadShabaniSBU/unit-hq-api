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

        // Databases that already ran the pre-versioning create migrations never
        // picked up template_version_id, because those files were edited in place.
        $this->adoptPreVersioningVariants();
        $this->adoptPreVersioningContractDocuments();

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

    private function adoptPreVersioningVariants(): void
    {
        if (Schema::hasColumn('template_variants', 'template_version_id')) {
            return;
        }

        Schema::table('template_variants', function (Blueprint $table): void {
            $table->unsignedBigInteger('template_version_id')->nullable();
        });

        foreach (DB::table('template_variants')->distinct()->pluck('template_family_id') as $familyId) {
            DB::table('template_variants')
                ->where('template_family_id', $familyId)
                ->update(['template_version_id' => $this->publishedVersionIdFor((int) $familyId)]);
        }

        if (DB::table('template_variants')->whereNull('template_version_id')->exists()) {
            throw new RuntimeException('template_variants.template_version_id could not be backfilled.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE template_variants ALTER COLUMN template_version_id SET NOT NULL');
        }

        Schema::table('template_variants', function (Blueprint $table): void {
            $table->foreign('template_version_id')->references('id')->on('template_versions')->cascadeOnDelete();
            $table->dropUnique('tv_family_locale_idx');
            $table->unique(['template_version_id', 'locale'], 'tv_version_locale_idx');
        });
    }

    private function adoptPreVersioningContractDocuments(): void
    {
        if (! Schema::hasTable('contract_documents') || Schema::hasColumn('contract_documents', 'template_version_id')) {
            return;
        }

        Schema::table('contract_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('template_version_id')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE contract_documents
            SET template_version_id = (
                SELECT template_version_id
                FROM template_variants
                WHERE template_variants.id = contract_documents.template_variant_id
            )
        SQL);

        if (DB::table('contract_documents')->whereNull('template_version_id')->exists()) {
            throw new RuntimeException('contract_documents.template_version_id could not be backfilled.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contract_documents ALTER COLUMN template_version_id SET NOT NULL');
        }

        Schema::table('contract_documents', function (Blueprint $table): void {
            $table->foreign('template_version_id')->references('id')->on('template_versions')->restrictOnDelete();
            $table->index('template_version_id', 'cd_template_version_idx');
        });
    }

    private function publishedVersionIdFor(int $familyId): int
    {
        $existing = DB::table('template_versions')
            ->where('template_family_id', $familyId)
            ->where('status', 'published')
            ->orderByDesc('version_number')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $number = (int) DB::table('template_versions')
            ->where('template_family_id', $familyId)
            ->max('version_number');

        return (int) DB::table('template_versions')->insertGetId([
            'template_family_id' => $familyId,
            'version_number' => $number + 1,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
