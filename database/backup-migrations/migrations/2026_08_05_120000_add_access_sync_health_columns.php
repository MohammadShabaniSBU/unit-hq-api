<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health surface for S15-04: last full sync + attention payload (unknown grants, counts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_provider_accounts', function (Blueprint $table): void {
            $table->timestampTz('last_full_synced_at')->nullable()->after('points_discovered_at');
            $table->json('sync_attention')->nullable()->after('last_full_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('access_provider_accounts', function (Blueprint $table): void {
            $table->dropColumn(['last_full_synced_at', 'sync_attention']);
        });
    }
};
