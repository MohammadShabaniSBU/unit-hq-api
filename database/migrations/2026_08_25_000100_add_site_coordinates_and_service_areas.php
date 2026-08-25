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
        Schema::table('sites', function (Blueprint $table): void {
            $table->decimal('latitude', 9, 6)->nullable()->after('location');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
        });

        $sites = DB::table('sites')->whereNotNull('location')->get(['id', 'location']);
        foreach ($sites as $row) {
            $location = $row->location;
            if (is_string($location)) {
                $decoded = json_decode($location, true);
                $location = is_array($decoded) ? $decoded : null;
            }
            if (! is_array($location)) {
                continue;
            }
            $lat = $location['lat'] ?? $location['latitude'] ?? null;
            $lng = $location['lng'] ?? $location['longitude'] ?? null;
            if ($lat === null || $lng === null) {
                continue;
            }
            DB::table('sites')->where('id', $row->id)->update([
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
        }

        Schema::create('site_service_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites');
            $table->string('kind');
            $table->string('value');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX site_service_areas_live_idx ON site_service_areas (site_id, kind, value) WHERE (archived_at IS NULL)');
            DB::statement('CREATE INDEX site_service_areas_lookup_idx ON site_service_areas (kind, value) WHERE (archived_at IS NULL)');
        } else {
            Schema::table('site_service_areas', function (Blueprint $table): void {
                $table->index(['kind', 'value']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_service_areas');
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
