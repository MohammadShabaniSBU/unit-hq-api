<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign provider accounts + webhook idempotency (S14-02).
 * Partial unique (provider) WHERE is_active requires PostgreSQL —
 * skipped on SQLite like other partial indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_provider_accounts', function (Blueprint $table): void {
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
            $table->timestamps();

            $table->unique('webhook_token', 'epa_token_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX epa_active_idx '
                .'ON esign_provider_accounts (provider) WHERE is_active'
            );
        }

        Schema::create('esign_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('esign_provider_account_id')
                ->constrained('esign_provider_accounts')
                ->cascadeOnDelete();
            $table->string('provider_event_id', 255);
            $table->json('payload');
            $table->string('processing_status', 16)->default('pending');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();

            $table->unique(
                ['esign_provider_account_id', 'provider_event_id'],
                'esign_webhook_events_account_event_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_webhook_events');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS epa_active_idx');
        }

        Schema::dropIfExists('esign_provider_accounts');
    }
};
