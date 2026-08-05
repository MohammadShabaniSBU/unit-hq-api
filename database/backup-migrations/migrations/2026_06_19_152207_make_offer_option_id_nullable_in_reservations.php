<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make offer_option_id nullable so that reservations can be created
 * manually from a deal without going through the offer pipeline.
 * Also add deal_id for direct deal-scoped queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('offer_option_id')->nullable()->change();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete()->after('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropColumn('deal_id');
            $table->foreignId('offer_option_id')->nullable(false)->change();
        });
    }
};
