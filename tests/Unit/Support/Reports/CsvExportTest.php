<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Reports;

use App\Support\Reports\CsvExporter;
use App\Support\Reports\ReportColumn;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportResult;
use PHPUnit\Framework\TestCase;

class CsvExportTest extends TestCase
{
    public function test_locale_bom_numeric(): void
    {
        $result = new ReportResult(
            columns: [
                ReportColumn::string('site_name', 'Site'),
                ReportColumn::int('unit_count', 'Units'),
                ReportColumn::money('sample_rent', 'Sample rent', 'EUR'),
            ],
            rows: [
                [
                    'site_name' => 'Madrid',
                    'unit_count' => 3,
                    'sample_rent' => '12.50',
                ],
            ],
        );

        $en = CsvExporter::export($result, 'en');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $en);
        $this->assertStringContainsString("Site,Units,Sample rent\r\n", $en);
        $this->assertStringContainsString("Madrid,3,12.50\r\n", $en);

        $es = CsvExporter::export($result, 'es');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $es);
        $this->assertStringContainsString("Site;Units;Sample rent\r\n", $es);
        $this->assertStringContainsString("Madrid;3;12,50\r\n", $es);

        // Money cells stay numeric (no currency symbol) for Excel import.
        $this->assertDoesNotMatchRegularExpression('/€|EUR/', preg_replace('/^.{3}/', '', $es));

        $filename = CsvExporter::filename('demo', new ReportFilters(
            siteIds: [7],
            asOf: '2026-08-01',
        ));
        $this->assertSame('demo-7-2026-08-01.csv', $filename);

        $filenameAll = CsvExporter::filename('demo', new ReportFilters);
        $this->assertSame('demo-all-all.csv', $filenameAll);
    }
}
