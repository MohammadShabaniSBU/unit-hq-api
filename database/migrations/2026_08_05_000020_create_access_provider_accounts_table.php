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
        Schema::create('access_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('display_name', 128);
            $table->text('credentials');
            $table->string('webhook_token', 64);
            $table->string('webhook_state', 16)->default('unconfigured');
            $table->json('webhook_endpoint_ids')->nullable();
            $table->string('status', 16)->default('disconnected');
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('discovered_points')->nullable();
            $table->timestampTz('points_discovered_at')->nullable();
            $table->timestampTz('last_full_synced_at')->nullable();
            $table->json('sync_attention')->nullable();
            $table->timestamps();
            $table->unique('webhook_token', 'apa_token_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX apa_active_idx ON access_provider_accounts USING btree (provider) WHERE is_active');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_provider_accounts');
    }
};
