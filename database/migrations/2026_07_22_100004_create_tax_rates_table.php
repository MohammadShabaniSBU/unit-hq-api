<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated & immutable, mirroring prices (03-pricing.md). A rate change
 * is always: insert a new row with the same code + a new effective_from, then
 * close the previous version by setting its effective_to. Never UPDATE rate
 * in place. Applied rates are snapshotted onto contract_items/charges, so
 * this table's effective dating exists for scheduling and audit — not to
 * protect signed contracts (the snapshot already does that).
 *
 * NOTE: The partial unique index uses raw SQL and requires PostgreSQL.
 * On SQLite/MySQL the index is skipped and application-layer validation must
 * enforce the "one default" constraint instead (see TaxRateController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->decimal('rate', 5, 2);
            $table->string('jurisdiction')->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('employees');
            $table->timestamps();

            $table->index(['code', 'effective_from', 'effective_to']);
        });

        // Partial unique index: at most one default rate at a time.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates (is_default) WHERE is_default = true'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
