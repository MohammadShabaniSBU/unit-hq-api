<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ChargeType;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportResult;
use App\Support\Reports\RevenueReport;
use PHPUnit\Framework\Attributes\Test;

class RevenueReportTest extends ChartReportTestCase
{
    #[Test]
    public function splits_currencies_and_drops_reversal_pairs(): void
    {
        $eur = $this->site('Madrid', 'EUR');
        $gbp = $this->site('London', 'GBP');
        $eurUnit = $this->unit($eur, 'E-1');
        $gbpUnit = $this->unit($gbp, 'G-1');

        $eurStay = $this->occupy($eurUnit, '80.00', 'EUR');
        $gbpStay = $this->occupy($gbpUnit, '90.00', 'GBP');

        $this->charge($eurStay['contract'], ChargeType::Rent, '100.00', '2026-01-15');
        $reversed = $this->charge($eurStay['contract'], ChargeType::Rent, '80.00', '2026-02-01');
        $this->charge($eurStay['contract'], ChargeType::Rent, '-80.00', '2026-02-01', $reversed->id);
        $this->charge($eurStay['contract'], ChargeType::Deposit, '500.00', '2026-01-15');
        $this->charge($gbpStay['contract'], ChargeType::Rent, '50.00', '2026-01-20', null, 'GBP');

        $result = (new RevenueReport)->runBounded(new ReportFilters(
            to: '2026-06-15',
            from: '2026-01-01',
        ));

        $keys = $this->chartKeys($result);
        $this->assertContains('revenue_by_stream:EUR', $keys);
        $this->assertContains('revenue_by_stream:GBP', $keys);
        $this->assertContains('revpam:EUR', $keys);
        $this->assertContains('revpam:GBP', $keys);
        $this->assertNotContains('revenue_by_stream', $keys);

        $eurRent = $this->seriesSum($result, 'revenue_by_stream:EUR', 'insights.charts.series.rent');
        $gbpRent = $this->seriesSum($result, 'revenue_by_stream:GBP', 'insights.charts.series.rent');
        $this->assertEqualsWithDelta(100.0, $eurRent, 0.001);
        $this->assertEqualsWithDelta(50.0, $gbpRent, 0.001);
        $this->assertSame('100.00', $result->meta['headlines_by_currency']['EUR']['rent']);
        $this->assertSame('10.00', $result->meta['headlines_by_currency']['EUR']['rentable_m2']);
        $this->assertStringStartsWith(
            'Definitions: docs/report-definitions.md — Revenue / Ingresos.',
            $result->meta['notes'][0],
        );
    }

    private function seriesSum(ReportResult $result, string $key, string $nameKey): float
    {
        foreach ($result->charts as $chart) {
            if ($chart->key !== $key) {
                continue;
            }
            foreach ($chart->series as $series) {
                if ($series->nameKey === $nameKey) {
                    return array_sum(array_map(
                        static fn (int|float|null $value): float => (float) $value,
                        $series->data,
                    ));
                }
            }
        }

        $this->fail("Missing series {$nameKey} on {$key}");
    }
}
