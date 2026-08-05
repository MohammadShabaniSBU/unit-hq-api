<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S14-00: contract_signature holds link to the awaiting contract (mirror
 * reservation_id idiom). Partial unique — one hold row per contract when set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_holds', function (Blueprint $table) {
            $table->foreignId('contract_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('contracts')
                ->nullOnDelete();
        });

        // One signature hold per (contract, unit); multi-unit contracts get one row each.
        DB::statement(
            'CREATE UNIQUE INDEX unit_holds_contract_unit_idx ON unit_holds (contract_id, unit_id) WHERE contract_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unit_holds_contract_unit_idx');

        Schema::table('unit_holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
