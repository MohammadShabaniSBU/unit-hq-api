<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S02-00 — contract_items become effective-dated versions.
 * Amount/currency live on prices; every version requires price_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('description');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->foreignId('supersedes_id')->nullable()->after('effective_to')
                ->constrained('contract_items')->nullOnDelete();
            $table->string('change_reason', 32)->nullable()->after('supersedes_id');
        });

        DB::table('contract_items')
            ->whereNull('effective_from')
            ->orderBy('id')
            ->each(function (object $item): void {
                $moveIn = DB::table('contracts')->where('id', $item->contract_id)->value('move_in_date')
                    ?? DB::table('contracts')->where('id', $item->contract_id)->value('start_date');

                if ($moveIn === null) {
                    throw new \RuntimeException("contract_items.id={$item->id} has no contract move_in/start date.");
                }

                DB::table('contract_items')->where('id', $item->id)->update([
                    'effective_from' => $moveIn,
                ]);
            });

        // Ensure every item has a price_id — mint contract-scoped prices for orphans.
        $orphans = DB::table('contract_items')->whereNull('price_id')->get();
        foreach ($orphans as $item) {
            $createdBy = DB::table('employees')->value('id');
            $priceId = DB::table('prices')->insertGetId([
                'priceable_type' => null,
                'priceable_id'   => null,
                'scope'          => 'contract',
                'amount'         => $item->amount,
                'currency'       => $item->currency ?? 'EUR',
                'effective_from' => null,
                'effective_to'   => null,
                'created_by'     => $createdBy,
                'created_at'     => now(),
            ]);
            DB::table('contract_items')->where('id', $item->id)->update(['price_id' => $priceId]);
        }

        Schema::table('contract_items', function (Blueprint $table) {
            $table->date('effective_from')->nullable(false)->change();
            $table->dropColumn(['amount', 'currency']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contract_items ALTER COLUMN price_id SET NOT NULL');
        } else {
            // SQLite: rebuild column as NOT NULL via Laravel change().
            Schema::table('contract_items', function (Blueprint $table) {
                $table->unsignedBigInteger('price_id')->nullable(false)->change();
            });
        }

        Schema::table('contract_items', function (Blueprint $table) {
            $table->index(['contract_id', 'effective_from'], 'contract_items_effective_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX contract_items_open_version_idx
             ON contract_items (contract_id, item_type, item_id)
             WHERE effective_to IS NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(
                'ALTER TABLE contract_items
                 ADD CONSTRAINT contract_items_no_version_overlap
                 EXCLUDE USING gist (
                     contract_id WITH =,
                     item_type WITH =,
                     item_id WITH =,
                     daterange(effective_from, effective_to, \'[)\') WITH &&
                 )'
            );
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('S02-00 version_contract_items is not reversible.');
    }
};
