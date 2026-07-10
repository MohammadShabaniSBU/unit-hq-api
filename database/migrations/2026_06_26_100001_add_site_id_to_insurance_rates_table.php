<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_rates', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('insurance_id')->constrained('sites');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
