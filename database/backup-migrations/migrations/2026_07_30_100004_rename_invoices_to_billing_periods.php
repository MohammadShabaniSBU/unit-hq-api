<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5: free the name `invoices` for S03's fiscal document.
 * Current table is a display grouping of charges over a period — billing_periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });

        Schema::rename('invoices', 'billing_periods');

        Schema::table('charges', function (Blueprint $table) {
            $table->renameColumn('invoice_id', 'billing_period_id');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->foreign('billing_period_id')
                ->references('id')
                ->on('billing_periods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropForeign(['billing_period_id']);
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->renameColumn('billing_period_id', 'invoice_id');
        });

        Schema::rename('billing_periods', 'invoices');

        Schema::table('charges', function (Blueprint $table) {
            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->nullOnDelete();
        });
    }
};
