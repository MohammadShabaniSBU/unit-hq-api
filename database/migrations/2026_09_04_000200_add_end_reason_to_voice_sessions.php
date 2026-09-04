<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table): void {
            $table->string('end_reason')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table): void {
            $table->dropColumn('end_reason');
        });
    }
};
