<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Reports;

use App\Support\Reports\ReportColumn;
use App\Support\Reports\ReportColumnType;
use App\Support\Reports\ReportResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportResultTest extends TestCase
{
    public function test_money_requires_currency_mixed_throws(): void
    {
        try {
            new ReportColumn('rent', 'Rent', ReportColumnType::Money, null);
            $this->fail('Expected money column without currency to throw.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires a currency', $e->getMessage());
        }

        try {
            ReportResult::moneyColumn('rent', 'Rent', []);
            $this->fail('Expected empty currency map to throw.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires a currency', $e->getMessage());
        }

        try {
            ReportResult::moneyColumn('rent', 'Rent', [
                'EUR' => ['10.00'],
                'GBP' => ['5.00'],
            ]);
            $this->fail('Expected mixed currencies to throw.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot mix currencies', $e->getMessage());
        }

        $column = ReportResult::moneyColumn('rent', 'Rent', ['eur' => ['10.00']]);
        $this->assertSame('EUR', $column->currency);
        $this->assertSame(ReportColumnType::Money, $column->type);

        $result = new ReportResult(
            columns: [$column, ReportColumn::int('n', 'N')],
            rows: [['rent' => '10.00', 'n' => 1]],
            meta: ['notes' => ['hello']],
        );
        $this->assertSame('EUR', $result->toArray()['columns'][0]['currency']);
        $this->assertSame(['notes' => ['hello']], $result->toArray()['meta']);
    }
}
