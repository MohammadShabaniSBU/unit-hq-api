<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InsightReportSource;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightVisibility;
use App\Models\InsightReport;
use App\Support\Insights\NativeReports;
use App\Support\Insights\NativeReportSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightsSyncTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function inserts_only_missing_keys_at_max_plus_one(): void
    {
        InsightReport::query()->create([
            'key' => 'dashboard',
            'source' => InsightReportSource::Native,
            'native_key' => 'dashboard',
            'icon' => 'i-lucide-layout-dashboard',
            'section' => 'overview',
            'sort_order' => 80,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => true,
        ]);

        $inserted = NativeReportSync::run();

        $this->assertNotContains('dashboard', $inserted);
        $this->assertCount(count(NativeReports::keys()) - 1, $inserted);
        $this->assertSame(80, InsightReport::query()->where('native_key', 'dashboard')->value('sort_order'));
        $this->assertSame(81, InsightReport::query()->where('native_key', 'rent-roll')->value('sort_order'));
        $this->assertSame(
            80 + count(NativeReports::keys()) - 1,
            InsightReport::query()->where('native_key', 'delinquency-trend')->value('sort_order'),
        );
    }

    #[Test]
    public function leaves_reordered_and_archived_rows_untouched(): void
    {
        NativeReportSync::run();

        $reordered = InsightReport::query()->where('native_key', 'rent-roll')->firstOrFail();
        $reordered->update([
            'sort_order' => 7,
            'icon' => 'i-lucide-bug',
        ]);

        $archived = InsightReport::query()->where('native_key', 'revenue')->firstOrFail();
        $archived->update(['archived_at' => now()]);

        $again = NativeReportSync::run();

        $this->assertSame([], $again);
        $reordered->refresh();
        $this->assertSame(7, $reordered->sort_order);
        $this->assertSame('i-lucide-bug', $reordered->icon);
        $this->assertSame(1, InsightReport::query()->where('native_key', 'revenue')->count());
        $this->assertNotNull($archived->fresh()?->archived_at);
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $exit = Artisan::call('insights:sync', ['--dry-run' => true]);

        $this->assertSame(0, InsightReport::query()->count());
        $output = Artisan::output();
        $this->assertStringContainsString('would insert', $output);
        $this->assertStringContainsString('revenue', $output);
        $this->assertSame(1, $exit);
    }

    #[Test]
    public function second_run_inserts_nothing(): void
    {
        Artisan::call('insights:sync');
        $count = InsightReport::query()->count();

        $exit = Artisan::call('insights:sync');

        $this->assertSame($count, InsightReport::query()->count());
        $this->assertStringContainsString('no rows inserted', Artisan::output());
        $this->assertSame(0, $exit);
    }
}
