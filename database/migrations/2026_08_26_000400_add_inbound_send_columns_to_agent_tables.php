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
        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->foreignId('subject_message_id')
                ->nullable()
                ->after('emitted_message_id')
                ->constrained('messages')
                ->nullOnDelete();
        });

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->timestamp('agent_handback_at')->nullable()->after('last_turn_at');
        });

        Schema::table('agent_pending_actions', function (Blueprint $table): void {
            $table->json('detail')->nullable()->after('preview');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX agent_conversation_messages_subject_message_id_uidx ON agent_conversation_messages (subject_message_id) WHERE subject_message_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS agent_conversation_messages_subject_message_id_uidx');
        }

        Schema::table('agent_pending_actions', function (Blueprint $table): void {
            $table->dropColumn('detail');
        });

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->dropColumn('agent_handback_at');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subject_message_id');
        });
    }
};
