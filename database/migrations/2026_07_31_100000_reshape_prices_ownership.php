<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S02-00a — prices own catalogue timing and ownership; junctions become static.
 *
 * - prices gain scope + priceable morph; billing_period dropped
 * - unit_class_rates / insurance_rates lose price_id (one row per pairing)
 * - Postgres: catalogue non-overlap exclusion constraint
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->string('priceable_type', 32)->nullable()->after('id');
            $table->unsignedBigInteger('priceable_id')->nullable()->after('priceable_type');
            $table->string('scope', 16)->nullable()->after('priceable_id');
        });

        $this->backfillPriceOwners('unit_class_rates', 'unit_class_rate', ['unit_class_id', 'site_id']);
        $this->backfillPriceOwners('insurance_rates', 'insurance_rate', ['insurance_id', 'site_id']);

        $orphans = DB::table('prices')->whereNull('scope')->count();
        if ($orphans > 0) {
            $ids = DB::table('prices')->whereNull('scope')->limit(20)->pluck('id')->implode(', ');
            throw new \RuntimeException(
                "S02-00a: {$orphans} price row(s) have no junction owner (sample ids: {$ids}). Refusing to guess."
            );
        }

        Schema::table('prices', function (Blueprint $table) {
            $table->string('scope', 16)->nullable(false)->change();
            $table->date('effective_from')->nullable()->change();
            $table->dropColumn('billing_period');
            $table->index(['priceable_type', 'priceable_id'], 'prices_priceable_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX prices_current_catalogue_idx
             ON prices (priceable_type, priceable_id)
             WHERE scope = \'catalogue\' AND effective_to IS NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE prices ADD CONSTRAINT prices_scope_shape CHECK (
                    (scope = \'catalogue\' AND effective_from IS NOT NULL AND priceable_id IS NOT NULL AND priceable_type IS NOT NULL)
                    OR
                    (scope = \'contract\' AND effective_from IS NULL AND effective_to IS NULL)
                )'
            );
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(
                'ALTER TABLE prices ADD CONSTRAINT prices_catalogue_no_overlap
                 EXCLUDE USING gist (
                     priceable_type WITH =,
                     priceable_id WITH =,
                     daterange(effective_from, effective_to, \'[)\') WITH &&
                 ) WHERE (scope = \'catalogue\')'
            );
        } else {
            // SQLite CHECK — same shape rule for local/CI default driver.
            DB::statement(
                'CREATE TRIGGER prices_scope_shape_insert
                 BEFORE INSERT ON prices
                 FOR EACH ROW
                 BEGIN
                     SELECT CASE
                         WHEN NOT (
                             (NEW.scope = \'catalogue\' AND NEW.effective_from IS NOT NULL AND NEW.priceable_id IS NOT NULL AND NEW.priceable_type IS NOT NULL)
                             OR
                             (NEW.scope = \'contract\' AND NEW.effective_from IS NULL AND NEW.effective_to IS NULL)
                         )
                         THEN RAISE(ABORT, \'prices_scope_shape\')
                     END;
                 END'
            );
            DB::statement(
                'CREATE TRIGGER prices_scope_shape_update
                 BEFORE UPDATE ON prices
                 FOR EACH ROW
                 BEGIN
                     SELECT CASE
                         WHEN NOT (
                             (NEW.scope = \'catalogue\' AND NEW.effective_from IS NOT NULL AND NEW.priceable_id IS NOT NULL AND NEW.priceable_type IS NOT NULL)
                             OR
                             (NEW.scope = \'contract\' AND NEW.effective_from IS NULL AND NEW.effective_to IS NULL)
                         )
                         THEN RAISE(ABORT, \'prices_scope_shape\')
                     END;
                 END'
            );
        }

        $this->collapseJunctions('unit_class_rates', 'unit_class_rate', ['unit_class_id', 'site_id'], 'offer_options', 'unit_class_rate_id');
        $this->collapseJunctions('insurance_rates', 'insurance_rate', ['insurance_id', 'site_id'], null, null);

        Schema::table('unit_class_rates', function (Blueprint $table) {
            $table->dropUnique(['unit_class_id', 'site_id', 'price_id']);
            $table->dropConstrainedForeignId('price_id');
            $table->unique(['unit_class_id', 'site_id'], 'unit_class_rates_pairing_unique');
        });

        Schema::table('insurance_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_id');
            $table->unique(['insurance_id', 'site_id'], 'insurance_rates_pairing_unique');
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('S02-00a reshape_prices_ownership is not reversible.');
    }

    /**
     * @param  list<string>  $pairingColumns
     */
    private function backfillPriceOwners(string $junctionTable, string $morphAlias, array $pairingColumns): void
    {
        $pairSelect = implode(', ', $pairingColumns);
        $groups = DB::table($junctionTable)
            ->selectRaw("{$pairSelect}, MIN(id) as keep_id")
            ->groupBy($pairingColumns)
            ->get();

        foreach ($groups as $group) {
            $keepId = (int) $group->keep_id;

            $query = DB::table($junctionTable);
            foreach ($pairingColumns as $col) {
                $query->where($col, $group->{$col});
            }
            $priceIds = $query->pluck('price_id')->unique()->filter()->all();

            if ($priceIds === []) {
                continue;
            }

            DB::table('prices')
                ->whereIn('id', $priceIds)
                ->update([
                    'priceable_type' => $morphAlias,
                    'priceable_id'   => $keepId,
                    'scope'          => 'catalogue',
                ]);
        }
    }

    /**
     * @param  list<string>  $pairingColumns
     */
    private function collapseJunctions(
        string $junctionTable,
        string $morphAlias,
        array $pairingColumns,
        ?string $repointTable,
        ?string $repointColumn,
    ): void {
        $pairSelect = implode(', ', $pairingColumns);
        $groups = DB::table($junctionTable)
            ->selectRaw("{$pairSelect}, MIN(id) as keep_id")
            ->groupBy($pairingColumns)
            ->get();

        foreach ($groups as $group) {
            $keepId = (int) $group->keep_id;

            $query = DB::table($junctionTable);
            foreach ($pairingColumns as $col) {
                $query->where($col, $group->{$col});
            }
            $dropIds = $query->where('id', '!=', $keepId)->pluck('id')->all();

            if ($dropIds === []) {
                continue;
            }

            if ($repointTable !== null && $repointColumn !== null) {
                DB::table($repointTable)
                    ->whereIn($repointColumn, $dropIds)
                    ->update([$repointColumn => $keepId]);
            }

            // Historical prices on dropped junctions already point at keep_id from backfill.
            DB::table('prices')
                ->where('priceable_type', $morphAlias)
                ->whereIn('priceable_id', $dropIds)
                ->update(['priceable_id' => $keepId]);

            DB::table($junctionTable)->whereIn('id', $dropIds)->delete();
        }
    }
};
