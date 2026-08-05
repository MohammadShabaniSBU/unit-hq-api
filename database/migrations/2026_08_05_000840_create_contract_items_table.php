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
        Schema::create('contract_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('item_type', 255);
            $table->unsignedBigInteger('item_id');
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->decimal('base_rate', 10, 2)->nullable();
            $table->date('discount_ends_at')->nullable();
            $table->foreignId('price_id')->constrained('prices')->nullOnDelete();
            $table->decimal('declared_goods_value', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->decimal('tax_rate_snapshot', 5, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->string('change_reason', 32)->nullable();
            $table->timestamp('discount_removed_at')->nullable();
            $table->foreignId('discount_removed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('discount_removed_reason')->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'effective_from'], 'contract_items_effective_idx');
            $table->index(['item_type', 'item_id'], 'contract_items_item_type_item_id_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('CREATE UNIQUE INDEX contract_items_open_version_idx ON contract_items USING btree (contract_id, item_type, item_id) WHERE (effective_to IS NULL)');
            DB::statement('ALTER TABLE contract_items ADD CONSTRAINT contract_items_no_version_overlap EXCLUDE USING gist (contract_id WITH =, item_type WITH =, item_id WITH =, daterange(effective_from, effective_to, \'[)\'::text) WITH &&)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_items');
    }
};
