<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InsightReportSource;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightVisibility;
use App\Models\InsightReport;
use App\Support\Insights\NativeReports;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Inserts one system native row per NativeReports entry.
 * Idempotent: keyed on native_key; never overwrites operator sort_order / labels / visibility.
 *
 *   php artisan db:seed --class=InsightReportSeeder
 */
class InsightReportSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $sortOrder = 0;

        foreach (NativeReports::all() as $nativeKey => $entry) {
            $exists = InsightReport::query()
                ->where('native_key', $nativeKey)
                ->exists();

            if ($exists) {
                $sortOrder++;

                continue;
            }

            InsightReport::query()->create([
                'key' => $nativeKey,
                'source' => InsightReportSource::Native,
                'native_key' => $nativeKey,
                'analytics_account_id' => null,
                'resource_kind' => null,
                'resource_ref' => null,
                'labels' => null,
                'description' => null,
                'icon' => $entry['icon'],
                'section' => $entry['section'],
                'sort_order' => $sortOrder,
                'visibility' => InsightVisibility::All,
                'site_scope_mode' => InsightSiteScopeMode::Inherit,
                'options' => [],
                'is_system' => true,
                'archived_at' => null,
                'created_by' => null,
            ]);

            $sortOrder++;
        }
    }
}
