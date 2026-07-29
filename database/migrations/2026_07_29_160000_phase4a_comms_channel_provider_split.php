<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4a: channel × provider split. Replaces provider_type + api_key with
 * channel + provider + encrypted credentials JSON + is_active, renames
 * correlation columns, and maps snich → sinch.
 *
 * Partial unique indexes require PostgreSQL; SQLite skips them (app-layer
 * uniqueness in the settings controller).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_provider_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_provider_unique');
        }

        Schema::table('communication_accounts', function (Blueprint $table): void {
            $table->string('channel')->nullable()->after('site_id');
            $table->string('provider')->nullable()->after('channel');
            $table->boolean('is_active')->default(false)->after('provider');
            $table->text('credentials')->nullable()->after('is_active');
            $table->renameColumn('webhook_provider_id', 'webhook_endpoint_id');
        });

        foreach (DB::table('communication_accounts')->get() as $row) {
            $providerType = (string) $row->provider_type;
            [$channel, $provider] = match ($providerType) {
                'brevo' => ['email', 'brevo'],
                'snich' => ['sms', 'sinch'],
                default => ['email', $providerType],
            };

            $credentialsCipher = null;

            if ($row->api_key !== null && $row->api_key !== '') {
                try {
                    $plain = Crypt::decryptString($row->api_key);
                    $credentialsCipher = Crypt::encryptString(json_encode(
                        ['api_key' => $plain],
                        JSON_THROW_ON_ERROR
                    ));
                } catch (\Throwable) {
                    // Unreadable ciphertext — leave credentials null; panel
                    // will show credentials_unreadable after reconnect.
                }
            }

            DB::table('communication_accounts')->where('id', $row->id)->update([
                'channel' => $channel,
                'provider' => $provider,
                'is_active' => true,
                'credentials' => $credentialsCipher,
            ]);
        }

        Schema::table('communication_accounts', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'provider_type']);
            $table->dropColumn(['provider_type', 'api_key']);
            $table->index(['site_id', 'channel']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX communication_accounts_company_channel_provider_unique '
                .'ON communication_accounts (channel, provider) '
                ."WHERE scope = 'company'"
            );
            DB::statement(
                'CREATE UNIQUE INDEX communication_accounts_site_channel_provider_unique '
                .'ON communication_accounts (site_id, channel, provider) '
                ."WHERE scope = 'site'"
            );
            DB::statement(
                'CREATE UNIQUE INDEX communication_accounts_company_channel_active_unique '
                .'ON communication_accounts (channel) '
                ."WHERE scope = 'company' AND is_active = true"
            );
            DB::statement(
                'CREATE UNIQUE INDEX communication_accounts_site_channel_active_unique '
                .'ON communication_accounts (site_id, channel) '
                ."WHERE scope = 'site' AND is_active = true"
            );
        } else {
            // SQLite (tests): approximate uniqueness without partial indexes.
            Schema::table('communication_accounts', function (Blueprint $table): void {
                $table->unique(
                    ['scope', 'site_id', 'channel', 'provider'],
                    'communication_accounts_scope_channel_provider_unique'
                );
            });
        }

        Schema::table('site_sender_identities', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'provider_type']);
            $table->renameColumn('provider_type', 'channel');
        });

        foreach (DB::table('site_sender_identities')->get() as $row) {
            $channel = match ((string) $row->channel) {
                'brevo' => 'email',
                'snich' => 'sms',
                default => $row->channel,
            };
            DB::table('site_sender_identities')->where('id', $row->id)->update([
                'channel' => $channel,
            ]);
        }

        Schema::table('site_sender_identities', function (Blueprint $table): void {
            $table->unique(['site_id', 'channel']);
        });

        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
            $table->renameColumn('message_id', 'provider_message_id');
        });

        Schema::table('interactions', function (Blueprint $table): void {
            $table->foreignId('communication_account_id')->nullable()->after('provider_message_id')
                ->constrained('communication_accounts')->nullOnDelete();
            $table->index('provider_message_id');
        });

        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
            $table->renameColumn('message_id', 'provider_message_id');
        });

        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->foreignId('communication_account_id')->nullable()->after('provider_message_id')
                ->constrained('communication_accounts')->nullOnDelete();
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('communication_account_id');
            $table->dropIndex(['provider_message_id']);
            $table->renameColumn('provider_message_id', 'message_id');
            $table->foreignId('account_id')->nullable()->after('message_id')
                ->constrained('communication_accounts')->nullOnDelete();
        });

        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('communication_account_id');
            $table->dropIndex(['provider_message_id']);
            $table->renameColumn('provider_message_id', 'message_id');
            $table->foreignId('account_id')->nullable()->after('message_id')
                ->constrained('communication_accounts')->nullOnDelete();
        });

        Schema::table('site_sender_identities', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'channel']);
            $table->renameColumn('channel', 'provider_type');
            $table->unique(['site_id', 'provider_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_channel_active_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_channel_active_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_company_channel_provider_unique');
            DB::statement('DROP INDEX IF EXISTS communication_accounts_site_channel_provider_unique');
        } else {
            Schema::table('communication_accounts', function (Blueprint $table): void {
                $table->dropUnique('communication_accounts_scope_channel_provider_unique');
            });
        }

        Schema::table('communication_accounts', function (Blueprint $table): void {
            $table->string('provider_type')->nullable()->after('site_id');
            $table->text('api_key')->nullable()->after('provider_type');
            $table->renameColumn('webhook_endpoint_id', 'webhook_provider_id');
        });

        Schema::table('communication_accounts', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'provider', 'is_active', 'credentials']);
            $table->index(['site_id', 'provider_type']);
        });
    }
};
