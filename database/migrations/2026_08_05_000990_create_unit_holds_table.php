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
        Schema::create('unit_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->string('hold_type', 24);
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->timestamps();
            $table->index(['unit_id', 'starts_on'], 'unit_holds_unit_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('CREATE INDEX unit_holds_active_idx ON unit_holds USING btree (unit_id) WHERE (released_at IS NULL)');
            DB::statement('CREATE UNIQUE INDEX unit_holds_contract_unit_idx ON unit_holds USING btree (contract_id, unit_id) WHERE (contract_id IS NOT NULL)');
            DB::statement('CREATE UNIQUE INDEX unit_holds_one_live_overlock_idx ON unit_holds USING btree (unit_id) WHERE (((hold_type)::text = \'overlock\'::text) AND (released_at IS NULL))');
            DB::statement('CREATE UNIQUE INDEX unit_holds_reservation_idx ON unit_holds USING btree (reservation_id) WHERE (reservation_id IS NOT NULL)');
            DB::statement('ALTER TABLE unit_holds ADD CONSTRAINT unit_holds_no_overlap EXCLUDE USING gist (unit_id WITH =, daterange(starts_on, ends_on, \'[)\'::text) WITH &&) WHERE (((released_at IS NULL) AND ((hold_type)::text <> \'overlock\'::text)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_holds');
    }
};
