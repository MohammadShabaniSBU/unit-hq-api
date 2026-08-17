<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InsightVisibility;
use App\Models\InsightReport;
use App\Support\Insights\NativeReports;
use Database\Seeders\InsightReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightReportSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_all_native_reports(): void
    {
        $this->seed(InsightReportSeeder::class);

        $keys = NativeReports::keys();
        $this->assertCount(10, $keys);

        $rows = InsightReport::query()
            ->where('source', 'native')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $this->assertCount(10, $rows);
        $this->assertSame($keys, $rows->pluck('native_key')->all());
        $this->assertSame($keys, $rows->pluck('key')->all());
        $this->assertTrue($rows->every(fn (InsightReport $r) => $r->is_system));
        $this->assertTrue($rows->every(fn (InsightReport $r) => $r->labels === null));
        $this->assertSame(range(0, 9), $rows->pluck('sort_order')->all());
    }

    #[Test]
    public function rerun_preserves_operator_edits(): void
    {
        $this->seed(InsightReportSeeder::class);

        $report = InsightReport::query()->where('native_key', 'rent-roll')->firstOrFail();
        $report->update([
            'sort_order' => 99,
            'labels' => ['es' => 'Cartera'],
            'visibility' => InsightVisibility::CompanyOnly,
        ]);

        $this->seed(InsightReportSeeder::class);

        $report->refresh();

        $this->assertSame(99, $report->sort_order);
        $this->assertSame(['es' => 'Cartera'], $report->labels);
        $this->assertSame(InsightVisibility::CompanyOnly, $report->visibility);
        $this->assertSame(10, InsightReport::query()->where('source', 'native')->count());
    }

    #[Test]
    public function rerun_does_not_resurrect_archived_native(): void
    {
        $this->seed(InsightReportSeeder::class);

        $report = InsightReport::query()->where('native_key', 'rent-roll')->firstOrFail();
        $report->update(['archived_at' => now()]);

        $this->seed(InsightReportSeeder::class);

        $rows = InsightReport::query()->where('native_key', 'rent-roll')->get();
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows->first()?->archived_at);
    }
}
