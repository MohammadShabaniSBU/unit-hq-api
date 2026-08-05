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
        Schema::create('communication_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 255);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('webhook_url_token', 255)->nullable();
            $table->string('webhook_endpoint_id', 255)->nullable();
            $table->timestamp('webhook_configured_at')->nullable();
            $table->string('status', 255)->default('disconnected');
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('channel', 255)->nullable();
            $table->string('provider', 255)->nullable();
            $table->boolean('is_active')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'channel'], 'communication_accounts_site_id_channel_index');
            $table->unique('webhook_url_token', 'communication_accounts_webhook_url_token_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX communication_accounts_company_channel_active_unique ON communication_accounts USING btree (channel) WHERE (((scope)::text = \'company\'::text) AND (is_active = true))');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_company_channel_provider_unique ON communication_accounts USING btree (channel, provider) WHERE ((scope)::text = \'company\'::text)');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_site_channel_active_unique ON communication_accounts USING btree (site_id, channel) WHERE (((scope)::text = \'site\'::text) AND (is_active = true))');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_site_channel_provider_unique ON communication_accounts USING btree (site_id, channel, provider) WHERE ((scope)::text = \'site\'::text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_accounts');
    }
};
