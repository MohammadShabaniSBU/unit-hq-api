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
        Schema::create('template_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_family_id')->constrained('template_families')->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('subject', 500)->nullable();
            $table->json('blocks')->nullable();
            $table->text('legacy_html')->nullable();
            $table->text('body_text')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique(['template_version_id', 'locale'], 'tv_version_locale_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createPublishedImmutabilityTrigger();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS tv_reject_published_mutation ON template_variants');
            DB::statement('DROP FUNCTION IF EXISTS tv_reject_published_mutation()');
        }

        Schema::dropIfExists('template_variants');
    }

    private function createPublishedImmutabilityTrigger(): void
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

CREATE TRIGGER tv_reject_published_mutation
BEFORE UPDATE OR DELETE ON template_variants
FOR EACH ROW
EXECUTE FUNCTION tv_reject_published_mutation();
SQL);
    }
};
