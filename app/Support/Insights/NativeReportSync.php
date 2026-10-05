<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Enums\InsightReportSource;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightVisibility;
use App\Models\InsightReport;
use Illuminate\Support\Facades\DB;

/**
 * Inserts missing native insight_reports rows. Never updates or deletes.
 * An existing native_key is left alone, including when the row is archived.
 */
final class NativeReportSync
{
    /**
     * @return list<string> Keys inserted, or keys that would be inserted when $dryRun is true.
     */
    public static function run(bool $dryRun = false): array
    {
        $existing = InsightReport::query()
            ->whereNotNull('native_key')
            ->pluck('native_key')
            ->all();
        $existingSet = array_fill_keys($existing, true);

        /** @var array<string, array{label_key: string, icon: string, section: string|null}> $missing */
        $missing = [];
        foreach (NativeReports::all() as $nativeKey => $entry) {
            if (isset($existingSet[$nativeKey])) {
                continue;
            }
            $missing[$nativeKey] = $entry;
        }

        if ($dryRun || $missing === []) {
            return array_keys($missing);
        }

        $inserted = [];

        DB::transaction(function () use ($missing, &$inserted): void {
            $sort = (int) (InsightReport::query()->max('sort_order') ?? -1);

            foreach ($missing as $nativeKey => $entry) {
                $sort++;

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
                    'sort_order' => $sort,
                    'visibility' => InsightVisibility::All,
                    'site_scope_mode' => InsightSiteScopeMode::Inherit,
                    'options' => [],
                    'is_system' => true,
                    'archived_at' => null,
                    'created_by' => null,
                ]);

                $inserted[] = $nativeKey;
            }
        });

        return $inserted;
    }
}
