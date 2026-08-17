<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InsightReportSource;
use App\Enums\InsightValidationStatus;
use App\Models\InsightProvisionedResource;
use App\Models\InsightReport;
use App\Support\Insights\NativeReports;
use App\Support\Insights\Provisioning\MetabaseBlueprints;
use Illuminate\Console\Command;

/**
 * Local registry/row and provisioned-blueprint drift. No HTTP.
 */
class InsightsCheckCommand extends Command
{
    protected $signature = 'insights:check';

    protected $description = 'Detect NativeReports and provisioned-blueprint drift (local, no HTTP)';

    public function handle(): int
    {
        $failed = false;

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
        } else {
            $failed = true;
            foreach ($missingRows as $key) {
                $this->error("Registry entry [{$key}] has no insight_reports row.");
            }
            foreach ($orphanRows as $key) {
                $this->error("insight_reports.native_key [{$key}] is not in NativeReports registry.");
            }
        }

        $resources = InsightProvisionedResource::query()
            ->with('insightReport')
            ->get();

        foreach ($resources as $resource) {
            if (! MetabaseBlueprints::has($resource->blueprint_key)) {
                $this->warn("Provisioned blueprint [{$resource->blueprint_key}] no longer ships. run insights:provision --prune");

                continue;
            }

            if ($resource->definition_hash !== MetabaseBlueprints::hash($resource->blueprint_key)) {
                $this->warn("Blueprint [{$resource->blueprint_key}] hash drifted. run insights:provision");
            }

            $report = $resource->insightReport;
            if ($report === null) {
                continue;
            }

            if ($report->resource_ref !== $resource->resource_ref) {
                $this->error("Report [{$report->key}] resource_ref diverges from the provisioned resource; the operator repointed it. Do not overwrite — inspect, then run insights:provision only if the remote id should be restored.");
                $failed = true;
            }

            if ($report->validation_status !== InsightValidationStatus::Valid) {
                $this->warn("Provisioned report [{$report->key}] validation_status={$report->validation_status->value}. run insights:validate, then insights:provision --force");
            }
        }

        $archivedSystem = InsightReport::query()
            ->where('is_system', true)
            ->whereNotNull('archived_at')
            ->orderBy('native_key')
            ->get(['key', 'native_key']);

        foreach ($archivedSystem as $report) {
            $this->info("Built-in [{$report->native_key}] is archived (supported operator choice).");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
