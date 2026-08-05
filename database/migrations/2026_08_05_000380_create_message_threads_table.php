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
        Schema::create('message_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts');
            $table->string('channel', 16);
            $table->string('subject', 500)->nullable();
            $table->string('channel_key', 255)->nullable();
            $table->timestampTz('last_message_at');
            $table->timestampTz('last_inbound_at')->nullable();
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->smallInteger('unread_count')->default('0');
            $table->timestamps();
            $table->index(['contact_id', 'channel'], 'mt_contact_idx');
            $table->index('last_message_at', 'mt_inbox_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX mt_number_channel_key_unique ON message_threads USING btree (contact_id, channel, channel_key) WHERE (((channel)::text = ANY ((ARRAY[\'sms\'::character varying, \'call\'::character varying, \'whatsapp\'::character varying])::text[])) AND (channel_key IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_threads');
    }
};
