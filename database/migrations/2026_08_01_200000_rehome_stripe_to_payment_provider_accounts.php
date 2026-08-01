<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-home Stripe credentials from per-site site_stripe_settings onto
 * legal-entity payment_provider_accounts (S06-00). No live data to migrate.
 *
 * Partial unique (legal_entity_id, provider) WHERE is_active uses raw SQL
 * and requires PostgreSQL — skipped on SQLite like other partial indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('display_name', 128);
            $table->string('publishable_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_endpoint_id', 64)->nullable();
            $table->string('provider_account_id', 64)->nullable();
            $table->string('account_token', 64);
            $table->string('status', 16)->default('disconnected');
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('account_token', 'ppa_token_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX ppa_entity_active_idx '
                .'ON payment_provider_accounts (legal_entity_id, provider) WHERE is_active'
            );
        }

        Schema::table('stripe_webhook_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
            $table->dropUnique(['stripe_event_id']);
            $table->foreignId('payment_provider_account_id')->nullable()->after('id')
                ->constrained('payment_provider_accounts')->nullOnDelete();
            $table->unique(
                ['payment_provider_account_id', 'stripe_event_id'],
                'stripe_webhook_events_account_event_unique'
            );
        });

        Schema::dropIfExists('site_stripe_settings');
    }

    public function down(): void
    {
        Schema::create('site_stripe_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('publishable_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_endpoint_id')->nullable();
            $table->string('webhook_route_token')->nullable()->unique();
            $table->string('status')->default('disconnected');
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('stripe_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('stripe_webhook_events_account_event_unique');
            $table->dropConstrainedForeignId('payment_provider_account_id');
            $table->unique('stripe_event_id');
            $table->foreignId('site_id')->nullable()->after('id')
                ->constrained('sites')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ppa_entity_active_idx');
        }

        Schema::dropIfExists('payment_provider_accounts');
    }
};
