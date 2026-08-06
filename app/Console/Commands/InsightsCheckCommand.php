<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InsightReportSource;
use App\Models\InsightReport;
use App\Support\Insights\NativeReports;
use Illuminate\Console\Command;

/**
 * Report registry/row mismatches in both directions. Does not fail boot.
 */
class InsightsCheckCommand extends Command
{
    protected $signature = 'insights:check';

    protected $description = 'Detect NativeReports registry ↔ insight_reports row mismatches';

    public function handle(): int
    {
        $registryKeys = NativeReports::keys();
        $rowKeys = InsightReport::query()
            ->where('source', InsightReportSource::Native)
            ->whereNotNull('native_key')
            ->pluck('native_key')
            ->all();

        $missingRows = array_values(array_diff($registryKeys, $rowKeys));
        $orphanRows = array_values(array_diff($rowKeys, $registryKeys));

        if ($missingRows === [] && $orphanRows === []) {
            $this->info('insights:check — registry and rows are in sync ('.count($registryKeys).' native reports).');

            return self::SUCCESS;
        }

        foreach ($missingRows as $key) {
            $this->error("Registry entry [{$key}] has no insight_reports row.");
        }

        foreach ($orphanRows as $key) {
            $this->error("insight_reports.native_key [{$key}] is not in NativeReports registry.");
        }

        return self::FAILURE;
    }
}
