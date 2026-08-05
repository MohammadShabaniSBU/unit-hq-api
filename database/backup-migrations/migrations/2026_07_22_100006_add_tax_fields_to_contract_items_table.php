<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax version applied to this line + the frozen percentage. Snapshotted
 * at signing so a later tax_rates version change never rewrites signed
 * contracts (mirrors price immutability, invariant #2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->after('price_id')->constrained('tax_rates')->nullOnDelete();
            $table->decimal('tax_rate_snapshot', 5, 2)->nullable()->after('tax_rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate_snapshot');
            $table->dropConstrainedForeignId('tax_rate_id');
        });
    }
};
