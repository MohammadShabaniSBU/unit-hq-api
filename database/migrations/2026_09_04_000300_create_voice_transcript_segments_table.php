<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_transcript_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_session_id')->constrained('voice_sessions')->cascadeOnDelete();
            $table->integer('sequence');
            $table->string('role');
            $table->text('text');
            $table->string('source');
            $table->foreignId('voice_session_turn_id')->nullable()->constrained('voice_session_turns');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['voice_session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_transcript_segments');
    }
};
