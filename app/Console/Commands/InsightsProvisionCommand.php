<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalyticsProvider;
use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use App\Enums\InsightReportSource;
use App\Enums\InsightResourceKind;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightValidationStatus;
use App\Enums\InsightVisibility;
use App\Models\AnalyticsAccount;
use App\Models\InsightProvisionedResource;
use App\Models\InsightReport;
use App\Models\InsightReportParam;
use App\Models\SystemEvent;
use App\Support\Credentials\CredentialMasker;
use App\Support\Insights\Contracts\ProvisionsResources;
use App\Support\Insights\Provisioning\MetabaseBlueprints;
use App\Support\Insights\Provisioning\ProvisionerRegistry;
use App\Support\Insights\Provisioning\ProvisioningException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ship Metabase dashboards from MetabaseBlueprints and register them
 * as embedded insight_reports. Deploy-time operator action, not cron.
 */
class InsightsProvisionCommand extends Command
{
    protected $signature = 'insights:provision
                            {--account= : Analytics account id}
                            {--collection=Keevaris : Metabase collection name}
                            {--database= : Metabase database name or id}
                            {--only=* : Blueprint keys to provision}
                            {--dry-run : Dry-run SQL only; no writes}
                            {--force : Re-push even when the hash matches}
                            {--prune : Archive remote resources whose blueprint no longer ships}
                            {--no-register : Skip insight_reports registration}';

    protected $description = 'Create or update shipped Metabase insight dashboards';

    public function handle(ProvisionerRegistry $registry): int
    {
        try {
            return $this->runProvision($registry);
        } catch (ProvisioningException $e) {
            $this->error($e->getMessage());
            $this->printBlocked();

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('insights:provision failed: '.$e->getMessage());
            report($e);
            $this->printBlocked();

            return self::FAILURE;
        }
    }

    private function runProvision(ProvisionerRegistry $registry): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        if (CredentialMasker::isUnreadable($account, 'credentials')) {
            $this->error('Analytics credentials could not be read.');

            return self::FAILURE;
        }

        $provisioner = $registry->forAccount($account);
        $databaseId = $provisioner->resolveDatabaseId(
            $this->option('database') ?: (string) config('insights.metabase_database', 'keevaris'),
        );

        $keys = $this->selectedKeys();
        if ($keys === null) {
            return self::FAILURE;
        }

        SystemEvent::record('insights.provision.started', $account, [
            'account_id' => $account->id,
            'keys' => $keys,
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $existing = InsightProvisionedResource::query()
            ->where('analytics_account_id', $account->id)
            ->get()
            ->keyBy('blueprint_key');

        $toWrite = [];
        foreach ($keys as $key) {
            $stored = $existing->get($key);
            $hash = MetabaseBlueprints::hash($key);
            if ($stored !== null
                && $stored->definition_hash === $hash
                && ! $this->option('force')
            ) {
                $this->line("skip {$key} (hash match)");

                continue;
            }
            $toWrite[] = $key;
        }

        if ($toWrite !== []) {
            $provisioner->dryRunQuery($databaseId, MetabaseBlueprints::sitesLookupSql(), []);
        }

        foreach ($toWrite as $key) {
            $this->dryRunBlueprint($provisioner, $databaseId, $key);
        }

        if ($this->option('dry-run')) {
            $this->info('insights:provision — dry-run complete, no writes.');
            $this->printBlocked();

            return self::SUCCESS;
        }

        $collectionId = $provisioner->ensureCollection((string) $this->option('collection'));
        $failed = false;
        $sitesCardId = $toWrite === []
            ? null
            : $this->upsertSitesLookup($provisioner, $databaseId, $collectionId, $existing);

        foreach ($toWrite as $key) {
            try {
                $this->writeBlueprint(
                    $provisioner,
                    $account,
                    $databaseId,
                    $collectionId,
                    $key,
                    $existing->get($key),
                    $sitesCardId,
                );
                $this->info("provisioned {$key}");
            } catch (ProvisioningException $e) {
                $this->error("{$key}: ".$e->getMessage());
                SystemEvent::record('insights.provision.failed', $account, [
                    'blueprint_key' => $key,
                    'message' => $e->getMessage(),
                ]);
                $failed = true;
            }
        }

        if ($this->option('prune')) {
            $this->prune($provisioner, $existing);
        }

        if ($failed) {
            $this->printBlocked();

            return self::FAILURE;
        }

        SystemEvent::record('insights.provision.committed', $account, [
            'account_id' => $account->id,
            'keys' => $toWrite,
        ]);
        $this->printBlocked();

        return self::SUCCESS;
    }

    private function resolveAccount(): ?AnalyticsAccount
    {
        $id = $this->option('account');
        if (is_string($id) && $id !== '') {
            $account = AnalyticsAccount::query()->find($id);
            if ($account === null) {
                $this->error("Analytics account [{$id}] was not found.");

                return null;
            }
        } else {
            $account = AnalyticsAccount::query()
                ->active()
                ->where('provider', AnalyticsProvider::Metabase)
                ->where('is_default', true)
                ->first()
                ?? AnalyticsAccount::query()
                    ->active()
                    ->where('provider', AnalyticsProvider::Metabase)
                    ->orderBy('id')
                    ->first();
        }

        if ($account === null) {
            $this->error('No active Metabase analytics account found.');

            return null;
        }

        if (! $account->isConnected()) {
            $this->error('Analytics account is not connected.');

            return null;
        }

        if ($account->provider !== AnalyticsProvider::Metabase) {
            $this->error('insights:provision requires a Metabase account.');

            return null;
        }

        return $account;
    }

    /**
     * @return list<string>|null
     */
    private function selectedKeys(): ?array
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only')));
        $all = MetabaseBlueprints::keys();

        if ($only === []) {
            return $all;
        }

        foreach ($only as $key) {
            if (! MetabaseBlueprints::has($key)) {
                $this->error("Unknown blueprint [{$key}].");

                return null;
            }
        }

        return $only;
    }

    private function dryRunBlueprint(ProvisionsResources $provisioner, int $databaseId, string $key): void
    {
        $entry = MetabaseBlueprints::get($key);
        if ($entry === null) {
            return;
        }

        $tags = MetabaseBlueprints::templateTags();
        foreach ($entry['cards'] as $card) {
            $provisioner->dryRunQuery($databaseId, $card['sql'], $tags);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, InsightProvisionedResource>  $existing
     */
    private function upsertSitesLookup(
        ProvisionsResources $provisioner,
        int $databaseId,
        int $collectionId,
        $existing,
    ): int {
        $existingId = null;
        foreach ($existing as $row) {
            $refs = is_array($row->card_refs) ? $row->card_refs : [];
            $ref = $refs[MetabaseBlueprints::SITES_LOOKUP_REF] ?? null;
            if (is_numeric($ref)) {
                $existingId = (int) $ref;
                break;
            }
        }

        return $provisioner->upsertCard($existingId, $databaseId, $collectionId, [
            'name' => MetabaseBlueprints::SITES_LOOKUP_NAME,
            'display' => 'table',
            'sql' => MetabaseBlueprints::sitesLookupSql(),
            'visualization_settings' => [],
            'template_tags' => [],
        ]);
    }

    private function writeBlueprint(
        ProvisionsResources $provisioner,
        AnalyticsAccount $account,
        int $databaseId,
        int $collectionId,
        string $key,
        ?InsightProvisionedResource $stored,
        ?int $sitesCardId,
    ): void {
        $entry = MetabaseBlueprints::get($key);
        if ($entry === null) {
            return;
        }

        /** @var array<string, int> $cardRefs */
        $cardRefs = is_array($stored?->card_refs) ? $stored->card_refs : [];
        $newRefs = [];
        $cardIds = [];
        if ($sitesCardId !== null) {
            $newRefs[MetabaseBlueprints::SITES_LOOKUP_REF] = $sitesCardId;
        }

        foreach ($entry['cards'] as $card) {
            $existingId = isset($cardRefs[$card['name']]) ? (int) $cardRefs[$card['name']] : null;
            $id = $provisioner->upsertCard($existingId, $databaseId, $collectionId, [
                'name' => $card['name'],
                'display' => $card['display'],
                'sql' => $card['sql'],
                'visualization_settings' => $card['visualization_settings'],
                'template_tags' => MetabaseBlueprints::templateTags(),
            ]);
            $newRefs[$card['name']] = $id;
            $cardIds[] = $id;
        }

        $dashcards = [];
        $dashcardId = -1;
        foreach ($entry['cards'] as $index => $card) {
            $cardId = $cardIds[$index];
            $dashcards[] = [
                'id' => $dashcardId--,
                'card_id' => $cardId,
                'row' => $card['row'],
                'col' => $card['col'],
                'size_x' => $card['size_x'],
                'size_y' => $card['size_y'],
                'parameter_mappings' => [
                    [
                        'parameter_id' => 'site_id',
                        'card_id' => $cardId,
                        'target' => ['variable', ['template-tag', 'site_id']],
                    ],
                ],
                'visualization_settings' => $card['visualization_settings'],
            ];
        }

        $existingDashboardId = $stored !== null ? (int) $stored->resource_ref : null;
        $dashboardId = $provisioner->upsertDashboard($existingDashboardId, $collectionId, [
            'name' => $entry['title'],
            'description' => $entry['description'],
            'parameters' => MetabaseBlueprints::parameters($sitesCardId),
            'dashcards' => $dashcards,
        ]);

        $provisioner->enableEmbedding('dashboard', $dashboardId, MetabaseBlueprints::embeddingParams());

        DB::transaction(function () use ($account, $key, $entry, $dashboardId, $newRefs, $stored): void {
            $report = null;
            if (! $this->option('no-register')) {
                $report = $this->registerReport($account, $key, $entry, $dashboardId);
            }

            InsightProvisionedResource::query()->updateOrCreate(
                [
                    'blueprint_key' => $key,
                    'analytics_account_id' => $account->id,
                ],
                [
                    'insight_report_id' => $report?->id ?? $stored?->insight_report_id,
                    'resource_kind' => InsightResourceKind::Dashboard->value,
                    'resource_ref' => (string) $dashboardId,
                    'card_refs' => $newRefs,
                    'definition_hash' => MetabaseBlueprints::hash($key),
                    'provisioned_at' => now(),
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function registerReport(
        AnalyticsAccount $account,
        string $key,
        array $entry,
        int $dashboardId,
    ): InsightReport {
        $reportKey = 'mb-'.$key;
        $report = InsightReport::query()->where('key', $reportKey)->first();

        if ($report === null) {
            $maxOrder = (int) InsightReport::query()->max('sort_order');
            $report = InsightReport::query()->create([
                'key' => $reportKey,
                'source' => InsightReportSource::Embedded,
                'native_key' => null,
                'analytics_account_id' => $account->id,
                'resource_kind' => InsightResourceKind::Dashboard,
                'resource_ref' => (string) $dashboardId,
                'labels' => [
                    'en' => $entry['title'],
                    'es' => $entry['title'],
                    'fr' => $entry['title'],
                ],
                'description' => [
                    'en' => $entry['description'],
                    'es' => $entry['description'],
                    'fr' => $entry['description'],
                ],
                'icon' => $entry['icon'],
                'section' => $entry['section'],
                'sort_order' => $maxOrder + 1,
                'visibility' => InsightVisibility::All,
                'site_scope_mode' => InsightSiteScopeMode::Inherit,
                'options' => [],
                'is_system' => false,
                'validation_status' => InsightValidationStatus::Valid,
                'validation_detail' => null,
                'last_validated_at' => now(),
            ]);
        } else {
            $report->resource_kind = InsightResourceKind::Dashboard;
            $report->resource_ref = (string) $dashboardId;
            $report->analytics_account_id = $account->id;
            $report->validation_status = InsightValidationStatus::Valid;
            $report->validation_detail = null;
            $report->last_validated_at = now();
            $report->save();
        }

        InsightReportParam::query()->updateOrCreate(
            [
                'insight_report_id' => $report->id,
                'name' => 'site_id',
            ],
            [
                'value_source' => InsightParamValueSource::Dynamic,
                'dynamic_key' => 'current_site_id',
                'static_value' => null,
                'binding' => InsightParamBinding::Locked,
                'is_required' => false,
                'sort_order' => 0,
            ],
        );

        return $report;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, InsightProvisionedResource>  $existing
     */
    private function prune(
        ProvisionsResources $provisioner,
        $existing,
    ): void {
        $shipped = array_fill_keys(MetabaseBlueprints::keys(), true);

        foreach ($existing as $row) {
            if (isset($shipped[$row->blueprint_key])) {
                continue;
            }

            $provisioner->archiveResource($row->resource_kind->value, (int) $row->resource_ref);
            $this->warn("pruned remote {$row->blueprint_key} (blueprint no longer ships); local registry row left for the operator");
        }
    }

    private function printBlocked(): void
    {
        $this->newLine();
        $this->line('BLOCKED native reports (not provisioned):');
        foreach (MetabaseBlueprints::BLOCKED as $key => $reason) {
            $this->line("  {$key} — {$reason}");
        }
    }
}
