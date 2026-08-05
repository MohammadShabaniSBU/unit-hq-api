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
            $table->smallInteger('unread_count')->default(0);
            $table->timestamps();

            $table->index(['contact_id', 'channel'], 'mt_contact_idx');
            $table->index(['last_message_at'], 'mt_inbox_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX mt_sms_call_channel_key_unique '
                .'ON message_threads (contact_id, channel, channel_key) '
                ."WHERE channel IN ('sms', 'call') AND channel_key IS NOT NULL"
            );
        } else {
            Schema::table('message_threads', function (Blueprint $table): void {
                $table->unique(
                    ['contact_id', 'channel', 'channel_key'],
                    'mt_sms_call_channel_key_unique'
                );
            });
        }

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_thread_id')->constrained('message_threads');
            $table->string('direction', 8);
            $table->string('status', 16);
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->string('from_address', 255);
            $table->string('to_address', 255);
            $table->string('provider', 32)->nullable();
            $table->foreignId('communication_account_id')->nullable()->constrained('communication_accounts')->nullOnDelete();
            $table->string('provider_message_id', 255)->nullable();
            $table->json('threading_evidence')->nullable();
            $table->string('source', 24);
            $table->json('source_ref')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->index(['message_thread_id', 'created_at'], 'm_thread_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX m_provider_idx '
                .'ON messages (provider, provider_message_id) '
                .'WHERE provider_message_id IS NOT NULL'
            );
        } else {
            Schema::table('messages', function (Blueprint $table): void {
                $table->unique(
                    ['provider', 'provider_message_id'],
                    'm_provider_idx'
                );
            });
        }

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages');
            $table->string('filename', 255);
            $table->string('mime_type', 128);
            $table->integer('size_bytes');
            $table->string('disk_path', 500);
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('interactions', function (Blueprint $table): void {
            $table->foreignId('message_id')->nullable()->after('communication_account_id')
                ->constrained('messages')->nullOnDelete();
        });

        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->foreignId('message_id')->nullable()->after('communication_account_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('message_id');
        });

        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('message_id');
        });

        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
