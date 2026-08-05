<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of a fact resolved at signing from the contract's items (same family
 * as billing_anchor_date / tax_rate_snapshot). Not cached derived state —
 * invariant 5 does not apply. Immutable after signing (invariant 35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // NOT NULL from the start — no live data to backfill; seeders always set it.
            $table->char('currency', 3)->default('EUR')->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
