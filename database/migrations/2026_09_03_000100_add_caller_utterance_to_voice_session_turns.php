<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_session_turns', function (Blueprint $table): void {
            $table->text('caller_utterance')->nullable()->after('answer_text');
        });
    }

    public function down(): void
    {
        Schema::table('voice_session_turns', function (Blueprint $table): void {
            $table->dropColumn('caller_utterance');
        });
    }
};
