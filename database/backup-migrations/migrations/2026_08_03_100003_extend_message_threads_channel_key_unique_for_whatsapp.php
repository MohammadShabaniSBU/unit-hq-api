<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Number-keyed threads (SMS/call/WhatsApp) share the same uniqueness rule.
 * SQLite already uses a full unique on (contact_id, channel, channel_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS mt_sms_call_channel_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX mt_number_channel_key_unique '
            .'ON message_threads (contact_id, channel, channel_key) '
            ."WHERE channel IN ('sms', 'call', 'whatsapp') AND channel_key IS NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS mt_number_channel_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX mt_sms_call_channel_key_unique '
            .'ON message_threads (contact_id, channel, channel_key) '
            ."WHERE channel IN ('sms', 'call') AND channel_key IS NOT NULL"
        );
    }
};
