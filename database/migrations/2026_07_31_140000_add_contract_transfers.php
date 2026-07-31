<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('transfer_billing', 24)
                ->default('prorate_immediately')
                ->after('move_out_settlement');
        });

        Schema::create('contract_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts');
            $table->foreignId('from_unit_id')->constrained('units');
            $table->foreignId('to_unit_id')->constrained('units');
            $table->foreignId('from_contract_item_id')->constrained('contract_items');
            $table->foreignId('to_contract_item_id')->constrained('contract_items');
            $table->date('transfer_date');
            $table->string('pricing_mode', 24);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index('contract_id', 'contract_transfers_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_transfers');

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('transfer_billing');
        });
    }
};
