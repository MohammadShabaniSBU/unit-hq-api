<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache of an external analytics system's state for settings-list warnings
 * without hitting the provider on every page load. This is not derived state
 * of our own domain (invariant 5 framing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_reports', function (Blueprint $table): void {
            $table->timestamp('last_validated_at')->nullable()->after('archived_at');
            $table->string('validation_status', 24)->default('unknown')->after('last_validated_at');
            $table->json('validation_detail')->nullable()->after('validation_status');
        });
    }

    public function down(): void
    {
        Schema::table('insight_reports', function (Blueprint $table): void {
            $table->dropColumn(['last_validated_at', 'validation_status', 'validation_detail']);
        });
    }
};
