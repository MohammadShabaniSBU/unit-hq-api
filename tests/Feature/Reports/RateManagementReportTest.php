<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Support\Reports\RateManagementReport;
use App\Support\Reports\ReportFilters;
use PHPUnit\Framework\Attributes\Test;

class RateManagementReportTest extends ChartReportTestCase
{
    #[Test]
    public function splits_currencies_and_skips_a_currency_mismatch(): void
    {
        $eur = $this->site('Madrid', 'EUR');
        $gbp = $this->site('London', 'GBP');
        $this->catalogue($eur, '100.00', 'EUR');
        $this->catalogue($gbp, '100.00', 'GBP');

        $this->occupy($this->unit($eur, 'E-1'), '80.00', 'EUR', '2025-01-01');
        $this->occupy($this->unit($eur, 'E-2'), '70.00', 'GBP', '2025-01-01');
        $this->occupy($this->unit($gbp, 'G-1'), '110.00', 'GBP', '2026-06-01');

        $result = (new RateManagementReport)->runBounded(new ReportFilters(
            asOf: '2026-06-15',
        ));

        $keys = $this->chartKeys($result);
        $this->assertContains('street_vs_in_place:EUR', $keys);
        $this->assertContains('street_vs_in_place:GBP', $keys);
        $this->assertNotContains('street_vs_in_place', $keys);
        $this->assertNotContains('variance_to_street', $keys);

        $this->assertSame(1, $result->meta['headlines_by_currency']['EUR']['tenants']);
        $this->assertSame(1, $result->meta['headlines_by_currency']['EUR']['below_street']);
        $this->assertSame(1, $result->meta['headlines_by_currency']['EUR']['ecri_eligible']);
        $this->assertSame('20.00', $result->meta['headlines_by_currency']['EUR']['monthly_gap_to_street']);
        $this->assertSame(1, $result->meta['headlines_by_currency']['GBP']['tenants']);
        $this->assertSame(0, $result->meta['headlines_by_currency']['GBP']['ecri_eligible']);
        $this->assertStringContainsString('ignores churn', $result->meta['notes'][1]);
    }
}
