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
        Schema::table('analytics_accounts', function (Blueprint $table): void {
            $table->string('private_base_url', 255)->nullable()->after('base_url');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_accounts', function (Blueprint $table): void {
            $table->dropColumn('private_base_url');
        });
    }
};
