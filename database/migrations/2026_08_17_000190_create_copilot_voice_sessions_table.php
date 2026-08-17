<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_voice_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('conversation_id', 36)->nullable();
            $table->string('vb_session_id', 64)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('turn_count')->default(0);
            $table->string('end_reason', 24)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_voice_sessions');
    }
};
