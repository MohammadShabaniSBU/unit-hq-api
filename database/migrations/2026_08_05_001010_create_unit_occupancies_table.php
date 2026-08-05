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
        Schema::create('unit_occupancies', function (Blueprint $table): void {
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
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('CREATE INDEX unit_occupancies_open_idx ON unit_occupancies USING btree (unit_id) WHERE (ended_on IS NULL)');
            DB::statement('ALTER TABLE unit_occupancies ADD CONSTRAINT unit_occupancies_no_overlap EXCLUDE USING gist (unit_id WITH =, daterange(started_on, ended_on, \'[)\'::text) WITH &&)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_occupancies');
    }
};
