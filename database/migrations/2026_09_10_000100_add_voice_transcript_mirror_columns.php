<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->string('voice_source', 16)->nullable()->after('fact_keys');
        });

        Schema::table('voice_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('mirrored_transcript_sequence')->nullable()->after('end_reason');
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropColumn('voice_source');
        });

        Schema::table('voice_sessions', function (Blueprint $table): void {
            $table->dropColumn('mirrored_transcript_sequence');
        });
    }
};
