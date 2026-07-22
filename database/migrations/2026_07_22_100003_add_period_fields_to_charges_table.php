<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service-window + tax snapshots on the append-only charge row. amount stays
 * the gross recorded fact (net_amount + tax_amount); tax_rate_snapshot is
 * filled once tax_rates exists (phase 2). contract_item_id traces each
 * first-period charge back to the line it was generated for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('contract_item_id')->nullable()->after('contract_id')->constrained('contract_items')->nullOnDelete();
            $table->date('period_start')->nullable()->after('charge_type');
            $table->date('period_end')->nullable()->after('period_start');
            $table->decimal('net_amount', 10, 2)->nullable()->after('period_end');
            $table->decimal('tax_rate_snapshot', 5, 2)->nullable()->after('amount');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_rate_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end', 'net_amount', 'tax_rate_snapshot', 'tax_amount']);
            $table->dropConstrainedForeignId('contract_item_id');
        });
    }
};
