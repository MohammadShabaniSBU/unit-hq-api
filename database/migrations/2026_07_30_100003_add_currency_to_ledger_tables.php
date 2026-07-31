<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised from contracts.currency at write time so allocation checks are
 * local column comparisons and Stripe webhooks fail loudly at insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            // NOT NULL from the start — no live data to backfill; seeders always set it.
            $table->char('currency', 3)->default('EUR')->after('amount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->default('EUR')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
