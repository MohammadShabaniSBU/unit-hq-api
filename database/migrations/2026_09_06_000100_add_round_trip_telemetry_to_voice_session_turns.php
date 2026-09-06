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
            $table->unsignedInteger('round_trip_ms')->nullable()->after('latency_ms');
            $table->boolean('filler_spoken')->default(false)->after('round_trip_ms');
        });
    }

    public function down(): void
    {
        Schema::table('voice_session_turns', function (Blueprint $table): void {
            $table->dropColumn(['round_trip_ms', 'filler_spoken']);
        });
    }
};
