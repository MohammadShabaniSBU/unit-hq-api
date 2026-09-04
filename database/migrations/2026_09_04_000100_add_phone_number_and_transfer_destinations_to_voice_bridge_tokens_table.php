<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_bridge_tokens', function (Blueprint $table): void {
            $table->string('phone_number')->nullable()->unique()->after('site_id');
            $table->string('main_line_number')->nullable()->after('phone_number');
            $table->string('voicemail_number')->nullable()->after('main_line_number');
        });
    }

    public function down(): void
    {
        Schema::table('voice_bridge_tokens', function (Blueprint $table): void {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'main_line_number', 'voicemail_number']);
        });
    }
};
