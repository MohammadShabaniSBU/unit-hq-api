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
        Schema::table('discounts', function (Blueprint $table): void {
            $table->boolean('agent_offerable')->default(false)->after('tracks_rate_changes');
            $table->json('customer_terms')->nullable()->after('agent_offerable');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE discounts ADD CONSTRAINT discounts_customer_terms_check CHECK (agent_offerable = false OR customer_terms IS NOT NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE discounts DROP CONSTRAINT IF EXISTS discounts_customer_terms_check');
        }

        Schema::table('discounts', function (Blueprint $table): void {
            $table->dropColumn(['agent_offerable', 'customer_terms']);
        });
    }
};
