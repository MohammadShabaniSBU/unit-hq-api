<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_av_number_nn ON attribute_values (definition_id, value_number) WHERE value_number IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_av_date_nn ON attribute_values (definition_id, value_date) WHERE value_date IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_av_option_nn ON attribute_values (definition_id, value_option_id) WHERE value_option_id IS NOT NULL');

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_av_text_trgm ON attribute_values USING gin (value_text gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_av_text_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_av_option_nn');
        DB::statement('DROP INDEX IF EXISTS idx_av_date_nn');
        DB::statement('DROP INDEX IF EXISTS idx_av_number_nn');
    }
};
