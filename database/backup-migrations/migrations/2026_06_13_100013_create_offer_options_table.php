<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * A partial unique index enforces that at most one option per offer can be
 * selected (selected_at IS NOT NULL). This is DB-level enforcement of the
 * business rule — application code should not rely on order-of-operations.
 *
 * NOTE: The partial index uses raw SQL and requires PostgreSQL.
 * On SQLite/MySQL the index is skipped and application-layer validation must
 * enforce the constraint instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('unit_class_rate_id')->constrained('unit_class_rates');
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->index('offer_id');
        });

        // Partial unique index: only one selected option per offer.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX offer_options_one_selected_per_offer '
                . 'ON offer_options (offer_id) WHERE selected_at IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_options');
    }
};
