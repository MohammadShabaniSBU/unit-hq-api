<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Site;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Trivial pipeline proof: one row per site with unit count and a sample
 * rent figure in the site's prefill currency (or EUR). Not a real KPI.
 */
final class DemoReport extends AbstractReport
{
    public static function name(): string
    {
        return 'demo';
    }

    public function maxQueries(): int
    {
        return 5;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $sitesQuery = Site::query()
            ->active()
            ->orderBy('name')
            ->orderBy('id');

        if ($filters->siteIds !== null) {
            $sitesQuery->whereIn('id', $filters->siteIds);
        }

        /** @var list<Site> $sites */
        $sites = $sitesQuery->get(['id', 'name', 'currency'])->all();

        if ($sites === []) {
            return new ReportResult(
                columns: [
                    ReportColumn::string('site_name', 'Site'),
                    ReportColumn::int('unit_count', 'Units'),
                    ReportColumn::money('sample_rent', 'Sample rent', 'EUR'),
                ],
                rows: [],
            );
        }

        $siteIds = array_map(static fn (Site $s): int => $s->id, $sites);

        /** @var array<int, int> $counts */
        $counts = Unit::query()
            ->whereIn('site_id', $siteIds)
            ->where('enabled', true)
            ->groupBy('site_id')
            ->select('site_id', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'site_id')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        // Demo money column: one currency for the whole result. Prefer the
        // first site's prefill currency; fall back to EUR. Multi-currency
        // sites get separate real reports in later tasks.
        $currency = strtoupper(trim((string) ($sites[0]->currency ?: 'EUR'))) ?: 'EUR';

        $rows = [];
        foreach ($sites as $site) {
            $rows[] = [
                'site_name' => $site->name,
                'unit_count' => $counts[$site->id] ?? 0,
                'sample_rent' => '0.00',
            ];
        }

        return new ReportResult(
            columns: [
                ReportColumn::string('site_name', 'Site'),
                ReportColumn::int('unit_count', 'Units'),
                ReportColumn::money('sample_rent', 'Sample rent', $currency),
            ],
            rows: $rows,
        );
    }
}
