<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider credentials for outbound communications (email/SMS). An account is
 * either company-scoped (scope='company', site_id null) or site-scoped
 * (scope='site', site_id required) per provider_type.
 *
 * api_key is encrypted at rest and never returned raw by the API — see
 * App\Support\Credentials for masking / blank-means-unchanged conventions.
 *
 * NOTE: Partial unique indexes use raw SQL and require PostgreSQL. On
 * SQLite/MySQL the indexes are skipped; app-layer validation in
 * CommunicationAccountController enforces the same uniqueness rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('provider_type');
            $table->text('api_key')->nullable();
            $table->string('webhook_url_token')->nullable()->unique();
            $table->string('webhook_provider_id')->nullable();
            $table->timestamp('webhook_configured_at')->nullable();
            $table->string('status')->default('disconnected');
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'provider_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX communication_accounts_company_provider_unique '
                .'ON communication_accounts (provider_type) '
                ."WHERE scope = 'company'"
            );
            DB::statement(
                'CREATE UNIQUE INDEX communication_accounts_site_provider_unique '
                .'ON communication_accounts (site_id, provider_type) '
                ."WHERE scope = 'site'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_accounts');
    }
};
