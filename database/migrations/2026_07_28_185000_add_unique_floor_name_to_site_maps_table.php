<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameDuplicateFloorNames();

        Schema::table('site_maps', function (Blueprint $table) {
            $table->unique(['site_id', 'floor_name']);
        });
    }

    public function down(): void
    {
        Schema::table('site_maps', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'floor_name']);
        });
    }

    /**
     * Rename colliding (site_id, floor_name) rows by suffixing " (2)", " (3)", etc.
     * before the unique index is added, so existing duplicate data doesn't
     * block the migration. The oldest row (lowest id) in each group keeps
     * its original name.
     */
    private function renameDuplicateFloorNames(): void
    {
        $duplicateGroups = DB::table('site_maps')
            ->select('site_id', 'floor_name')
            ->groupBy('site_id', 'floor_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $rows = DB::table('site_maps')
                ->where('site_id', $group->site_id)
                ->where('floor_name', $group->floor_name)
                ->orderBy('id')
                ->get(['id', 'floor_name']);

            $existingNames = DB::table('site_maps')
                ->where('site_id', $group->site_id)
                ->pluck('floor_name')
                ->all();

            $suffix = 2;

            foreach ($rows->skip(1) as $row) {
                do {
                    $candidate = $row->floor_name.' ('.$suffix.')';
                    $suffix++;
                } while (in_array($candidate, $existingNames, true));

                DB::table('site_maps')->where('id', $row->id)->update(['floor_name' => $candidate]);
                $existingNames[] = $candidate;

                Log::info('Renamed duplicate site_maps.floor_name to satisfy unique(site_id, floor_name).', [
                    'site_map_id' => $row->id,
                    'site_id' => $group->site_id,
                    'old_floor_name' => $row->floor_name,
                    'new_floor_name' => $candidate,
                ]);
            }
        }
    }
};
