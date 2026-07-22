<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rate -> amount: the line's effective period charge (snapshot; supports a
 * per-contract override without touching catalog Price rows — invariant #2).
 * price_id records provenance only. declared_goods_value/description are
 * free-form per-line fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->renameColumn('rate', 'amount');
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->foreignId('price_id')->nullable()->after('amount')->constrained('prices')->nullOnDelete();
            $table->decimal('declared_goods_value', 10, 2)->nullable()->after('discount_ends_at');
            $table->text('description')->nullable()->after('declared_goods_value');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'declared_goods_value']);
            $table->dropConstrainedForeignId('price_id');
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->renameColumn('amount', 'rate');
        });
    }
};
