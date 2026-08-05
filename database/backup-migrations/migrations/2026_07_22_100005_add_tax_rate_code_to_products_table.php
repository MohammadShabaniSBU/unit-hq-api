<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable default tax pointer so the contract form can prefill. Referenced
 * by tax_rates.code (stable across rate versions), not tax_rate_id, so a
 * scheduled rate change flows through without repointing every product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_classes', function (Blueprint $table) {
            $table->string('tax_rate_code')->nullable()->after('current_price_id');
        });

        Schema::table('insurances', function (Blueprint $table) {
            $table->string('tax_rate_code')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('unit_classes', function (Blueprint $table) {
            $table->dropColumn('tax_rate_code');
        });

        Schema::table('insurances', function (Blueprint $table) {
            $table->dropColumn('tax_rate_code');
        });
    }
};
