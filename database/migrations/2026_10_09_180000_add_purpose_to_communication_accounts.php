<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S30-06: a company may keep one active account per (channel, purpose) so a
 * marketing stream can sit beside the transactional one (M7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_accounts', function (Blueprint $table): void {
            $table->string('purpose', 16)->default('all');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_channel_active_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_channel_provider_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_channel_active_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_channel_provider_unique');

            DB::statement('CREATE UNIQUE INDEX communication_accounts_company_channel_active_unique ON communication_accounts USING btree (channel, purpose) WHERE (((scope)::text = \'company\'::text) AND (is_active = true))');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_company_channel_provider_unique ON communication_accounts USING btree (channel, provider, purpose) WHERE ((scope)::text = \'company\'::text)');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_site_channel_active_unique ON communication_accounts USING btree (site_id, channel, purpose) WHERE (((scope)::text = \'site\'::text) AND (is_active = true))');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_site_channel_provider_unique ON communication_accounts USING btree (site_id, channel, provider, purpose) WHERE ((scope)::text = \'site\'::text)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_channel_active_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_channel_provider_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_channel_active_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_channel_provider_unique');

            DB::statement('DELETE FROM communication_accounts WHERE purpose <> \'all\'');

            DB::statement('CREATE UNIQUE INDEX communication_accounts_company_channel_active_unique ON communication_accounts USING btree (channel) WHERE (((scope)::text = \'company\'::text) AND (is_active = true))');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_company_channel_provider_unique ON communication_accounts USING btree (channel, provider) WHERE ((scope)::text = \'company\'::text)');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_site_channel_active_unique ON communication_accounts USING btree (site_id, channel) WHERE (((scope)::text = \'site\'::text) AND (is_active = true))');
            DB::statement('CREATE UNIQUE INDEX communication_accounts_site_channel_provider_unique ON communication_accounts USING btree (site_id, channel, provider) WHERE ((scope)::text = \'site\'::text)');
        } else {
            DB::table('communication_accounts')->where('purpose', '<>', 'all')->delete();
        }

        Schema::table('communication_accounts', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
