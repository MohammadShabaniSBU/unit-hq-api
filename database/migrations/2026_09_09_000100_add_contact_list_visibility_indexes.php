<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_sites', function (Blueprint $table): void {
            $table->index(['site_id', 'contact_id'], 'contact_sites_site_contact_index');
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->index(['site_id', 'contact_id'], 'deals_site_contact_index');
        });

        Schema::table('unit_occupancies', function (Blueprint $table): void {
            $table->index('contract_id', 'unit_occupancies_contract_id_index');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->index('created_at', 'contacts_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('contact_sites', function (Blueprint $table): void {
            $table->dropIndex('contact_sites_site_contact_index');
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_site_contact_index');
        });

        Schema::table('unit_occupancies', function (Blueprint $table): void {
            $table->dropIndex('unit_occupancies_contract_id_index');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_created_at_index');
        });
    }
};
