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
        Schema::create('access_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_provider_account_id')->constrained('access_provider_accounts')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites');
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->string('point_type', 16);
            $table->string('provider_point_id', 128);
            $table->string('label', 128);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ap_provider_idx ON access_points USING btree (access_provider_account_id, provider_point_id) WHERE (archived_at IS NULL)');
            DB::statement('CREATE UNIQUE INDEX ap_unit_idx ON access_points USING btree (unit_id) WHERE ((unit_id IS NOT NULL) AND (archived_at IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_points');
    }
};
