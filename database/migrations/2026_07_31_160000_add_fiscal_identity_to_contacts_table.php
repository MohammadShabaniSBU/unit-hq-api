<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('billing_name')->nullable()->after('company');
            $table->string('tax_id', 64)->nullable()->after('billing_name');
            $table->string('tax_id_type', 16)->nullable()->after('tax_id');
            $table->string('billing_address_line1')->nullable()->after('tax_id_type');
            $table->string('billing_address_line2')->nullable()->after('billing_address_line1');
            $table->string('billing_city', 128)->nullable()->after('billing_address_line2');
            $table->string('billing_postal_code', 32)->nullable()->after('billing_city');
            $table->char('billing_country_code', 2)->nullable()->after('billing_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'billing_name',
                'tax_id',
                'tax_id_type',
                'billing_address_line1',
                'billing_address_line2',
                'billing_city',
                'billing_postal_code',
                'billing_country_code',
            ]);
        });
    }
};
