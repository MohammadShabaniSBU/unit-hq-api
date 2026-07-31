<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of prices.currency at signing — not re-derived at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            // NOT NULL from the start — no live data to backfill; seeders always set it.
            $table->char('currency', 3)->default('EUR')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
