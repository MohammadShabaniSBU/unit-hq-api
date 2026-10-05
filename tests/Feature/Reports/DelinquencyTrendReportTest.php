<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ChargeType;
use App\Support\Reports\DelinquencyTrendReport;
use App\Support\Reports\ReportFilters;
use PHPUnit\Framework\Attributes\Test;

class DelinquencyTrendReportTest extends ChartReportTestCase
{
    #[Test]
    public function past_checkpoint_ignores_later_allocations(): void
    {
        $site = $this->site('Madrid', 'EUR');
        $stay = $this->occupy($this->unit($site, 'D-1'), '80.00', 'EUR', '2026-01-01');
        $charge = $this->charge($stay['contract'], ChargeType::Rent, '100.00', '2026-04-01');
        $this->allocate($charge, '40.00', '2026-05-15 12:00:00');
        $this->allocate($charge, '60.00', '2026-06-10 12:00:00');

        $result = (new DelinquencyTrendReport)->runBounded(new ReportFilters(
            siteIds: [$site->id],
            asOf: '2026-06-15',
            to: '2026-06-15',
        ));

        $may = collect($result->rows)->firstWhere('month', '2026-05');
        $june = collect($result->rows)->firstWhere('month', '2026-06');
        $this->assertNotNull($may);
        $this->assertNotNull($june);
        $this->assertSame('60.00', $may['total']);
        $this->assertSame('60.00', $may['31-60']);
        $this->assertSame('0.00', $june['total']);

        $keys = $this->chartKeys($result);
        $this->assertContains('overdue_by_bucket:EUR', $keys);
        $this->assertNotContains('overdue_by_bucket', $keys);
        $this->assertContains('delinquent_tenants', $keys);
        $this->assertStringContainsString('end of day UTC', $result->meta['notes'][1]);
    }
}
