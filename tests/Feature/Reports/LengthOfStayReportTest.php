<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ContractEndedReason;
use App\Support\Reports\LengthOfStayReport;
use App\Support\Reports\ReportFilters;
use PHPUnit\Framework\Attributes\Test;

class LengthOfStayReportTest extends ChartReportTestCase
{
    #[Test]
    public function a_transfer_is_not_a_leaver(): void
    {
        $site = $this->site('Madrid', 'EUR');
        $from = $this->unit($site, 'A-1');
        $to = $this->unit($site, 'A-2');

        $moved = $this->occupy(
            $from,
            '80.00',
            'EUR',
            '2025-01-01',
            '2026-03-01',
            ContractEndedReason::TransferredOut->value,
        );
        $this->occupy($to, '90.00', 'EUR', '2026-03-01', null, null, $moved['contract']);

        $this->occupy(
            $this->unit($site, 'A-3'),
            '80.00',
            'EUR',
            '2025-06-01',
            '2026-02-01',
            ContractEndedReason::Vacated->value,
        );

        $result = (new LengthOfStayReport)->runBounded(new ReportFilters(
            to: '2026-06-15',
        ));

        $this->assertSame(1, $result->meta['headlines']['current_tenants']);
        $this->assertSame(1, $result->meta['headlines']['leavers']);
        $this->assertContains('tenure_current', $this->chartKeys($result));
        $this->assertContains('cohort_retention', $this->chartKeys($result));
        $this->assertStringStartsWith(
            'Definitions: docs/report-definitions.md — Length of stay / Duración de estancia.',
            $result->meta['notes'][0],
        );
    }
}
