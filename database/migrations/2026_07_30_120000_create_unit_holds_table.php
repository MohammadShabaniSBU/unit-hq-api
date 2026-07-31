<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fact table: a unit is blocked for a typed reason over a civil-date range.
 * Availability is derived from these rows (and unit_occupancies) — never a
 * cached is_available column (invariant 5 / 36).
 *
 * starts_on / ends_on are DATE (civil dates via SiteClock). released_at is a
 * TIMESTAMP (absolute instant of early release).
 *
 * Postgres enforces non-overlap on unreleased blocking holds via gist exclusion
 * on half-open dateranges [starts_on, ends_on). Overlock is exempt (coexists
 * with occupancy). SQLite relies on HoldGuard + write serialisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->string('hold_type', 24);
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['unit_id', 'starts_on'], 'unit_holds_unit_idx');
        });

        DB::statement(
            'CREATE INDEX unit_holds_active_idx ON unit_holds (unit_id) WHERE released_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX unit_holds_reservation_idx ON unit_holds (reservation_id) WHERE reservation_id IS NOT NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(
                'ALTER TABLE unit_holds
                 ADD CONSTRAINT unit_holds_no_overlap
                 EXCLUDE USING gist (
                     unit_id WITH =,
                     daterange(starts_on, ends_on, \'[)\') WITH &&
                 ) WHERE (released_at IS NULL AND hold_type <> \'overlock\')'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_holds DROP CONSTRAINT IF EXISTS unit_holds_no_overlap');
        }

        Schema::dropIfExists('unit_holds');
    }
};
