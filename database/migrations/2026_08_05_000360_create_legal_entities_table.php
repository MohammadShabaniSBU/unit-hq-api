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
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('legal_name', 255);
            $table->string('trading_name', 255)->nullable();
            $table->string('tax_id', 64);
            $table->string('tax_id_type', 16);
            $table->string('vat_number', 64)->nullable();
            $table->char('country_code', 2);
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 128);
            $table->string('postal_code', 32);
            $table->string('fiscal_regime', 24)->default('none');
            $table->string('sepa_creditor_id', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('archived_at', 'legal_entities_archived_at_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX legal_entities_tax_id_idx ON legal_entities USING btree (tax_id) WHERE (archived_at IS NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_entities');
    }
};
