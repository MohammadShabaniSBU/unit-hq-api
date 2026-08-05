<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('EUR');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('employees');
            $table->timestamp('created_at')->useCurrent();
            $table->string('priceable_type', 32)->nullable();
            $table->unsignedBigInteger('priceable_id')->nullable();
            $table->string('scope', 16);
            $table->index(['effective_from', 'effective_to'], 'prices_effective_from_effective_to_index');
            $table->index('effective_to', 'prices_effective_to_index');
            $table->index(['priceable_type', 'priceable_id'], 'prices_priceable_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('CREATE UNIQUE INDEX prices_current_catalogue_idx ON prices USING btree (priceable_type, priceable_id) WHERE (((scope)::text = \'catalogue\'::text) AND (effective_to IS NULL))');
            DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_scope_shape CHECK (((((scope)::text = \'catalogue\'::text) AND (effective_from IS NOT NULL) AND (priceable_id IS NOT NULL) AND (priceable_type IS NOT NULL)) OR (((scope)::text = \'contract\'::text) AND (effective_from IS NULL) AND (effective_to IS NULL))))');
            DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_catalogue_no_overlap EXCLUDE USING gist (priceable_type WITH =, priceable_id WITH =, daterange(effective_from, effective_to, \'[)\'::text) WITH &&) WHERE (((scope)::text = \'catalogue\'::text))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
