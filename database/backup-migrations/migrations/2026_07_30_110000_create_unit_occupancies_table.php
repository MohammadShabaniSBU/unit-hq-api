<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fact table: a contract occupies a unit over a civil-date range.
 * Availability is derived from these rows (and unit_holds later) — never a
 * cached is_available column (invariant 5).
 *
 * Postgres enforces non-overlap via gist exclusion on half-open dateranges
 * [started_on, ended_on). SQLite relies on OccupancyGuard + write serialisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_occupancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('contract_id')->constrained('contracts');
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('ended_reason', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['unit_id', 'started_on'], 'unit_occupancies_unit_idx');
            $table->index('contract_id', 'unit_occupancies_contract_idx');
        });

        // Partial open-occupancy index — both SQLite and Postgres support WHERE.
        DB::statement(
            'CREATE INDEX unit_occupancies_open_idx ON unit_occupancies (unit_id) WHERE ended_on IS NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(
                'ALTER TABLE unit_occupancies
                 ADD CONSTRAINT unit_occupancies_no_overlap
                 EXCLUDE USING gist (
                     unit_id WITH =,
                     daterange(started_on, ended_on, \'[)\') WITH &&
                 )'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_occupancies DROP CONSTRAINT IF EXISTS unit_occupancies_no_overlap');
        }

        Schema::dropIfExists('unit_occupancies');
    }
};
