<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access provider accounts, webhooks, events + FK/PIN columns (S15-01).
 * Partial unique (provider) WHERE is_active requires PostgreSQL.
 */
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
            $table->timestamps();

            $table->unique('webhook_token', 'apa_token_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX apa_active_idx '
                .'ON access_provider_accounts (provider) WHERE is_active'
            );
        }

        Schema::create('access_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_provider_account_id')
                ->constrained('access_provider_accounts')
                ->cascadeOnDelete();
            $table->string('provider_event_id', 255);
            $table->json('payload');
            $table->string('processing_status', 16)->default('pending');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();

            $table->unique(
                ['access_provider_account_id', 'provider_event_id'],
                'access_webhook_events_account_event_unique'
            );
        });

        Schema::create('access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_point_id')
                ->nullable()
                ->constrained('access_points')
                ->nullOnDelete();
            $table->foreignId('contact_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();
            $table->foreignId('access_grant_id')
                ->nullable()
                ->constrained('access_grants')
                ->nullOnDelete();
            $table->string('event_type', 16);
            $table->timestampTz('occurred_at');
            $table->string('provider_credential_ref', 128)->nullable();
            $table->string('provider_point_id', 128)->nullable();
            $table->json('raw');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['access_point_id', 'occurred_at'], 'ae_point_time_idx');
            $table->index(['contact_id', 'occurred_at'], 'ae_contact_idx');
        });

        Schema::table('access_points', function (Blueprint $table): void {
            $table->foreign('access_provider_account_id', 'ap_account_fk')
                ->references('id')
                ->on('access_provider_accounts')
                ->restrictOnDelete();
        });

        Schema::table('access_grants', function (Blueprint $table): void {
            $table->text('pin')->nullable();
            $table->timestampTz('pin_shown_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $table): void {
            $table->dropColumn(['pin', 'pin_shown_at']);
        });

        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropForeign('ap_account_fk');
        });

        Schema::dropIfExists('access_events');
        Schema::dropIfExists('access_webhook_events');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS apa_active_idx');
        }

        Schema::dropIfExists('access_provider_accounts');
    }
};
