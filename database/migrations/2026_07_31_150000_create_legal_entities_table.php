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
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('tax_id', 64);
            $table->string('tax_id_type', 16);
            $table->string('vat_number', 64)->nullable();
            $table->char('country_code', 2);
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 128);
            $table->string('postal_code', 32);
            $table->string('fiscal_regime', 24)->default('none');
            $table->string('sepa_creditor_id', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
        });

        DB::statement(
            'CREATE UNIQUE INDEX legal_entities_tax_id_idx ON legal_entities (tax_id) WHERE archived_at IS NULL'
        );

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')
                ->nullable()
                ->after('currency')
                ->constrained('legal_entities');
        });

        $entityId = DB::table('legal_entities')->insertGetId([
            'legal_name' => 'PENDING GESTOR',
            'trading_name' => null,
            'tax_id' => 'PENDING-GESTOR',
            'tax_id_type' => 'nif',
            'vat_number' => null,
            'country_code' => 'ES',
            'address_line1' => 'Calle Placeholder 1',
            'address_line2' => null,
            'city' => 'Madrid',
            'postal_code' => '28001',
            'fiscal_regime' => 'none',
            'sepa_creditor_id' => null,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->whereNull('legal_entity_id')->update([
            'legal_entity_id' => $entityId,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sites ALTER COLUMN legal_entity_id SET NOT NULL');
        } else {
            Schema::table('sites', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_entity_id');
        });

        Schema::dropIfExists('legal_entities');
    }
};
