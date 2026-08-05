<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultTimezone = (string) config('app.timezone', 'UTC');

        Schema::table('sites', function (Blueprint $table) use ($defaultTimezone) {
            $table->string('timezone')->default($defaultTimezone)->after('country_id');
            $table->timestamp('archived_at')->nullable()->after('timezone');
            $table->string('code')->nullable()->after('name');
            $table->string('postal_code')->nullable()->after('city');
            $table->string('state_region')->nullable()->after('postal_code');
            $table->string('address_line_2')->nullable()->after('address');
            $table->unique('code');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn([
                'timezone',
                'archived_at',
                'code',
                'postal_code',
                'state_region',
                'address_line_2',
            ]);
        });
    }
};
