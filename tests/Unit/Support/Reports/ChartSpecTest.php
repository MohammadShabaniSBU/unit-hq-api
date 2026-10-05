<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Reports;

use App\Support\Reports\Charts\CategoryKind;
use App\Support\Reports\Charts\ChartFormat;
use App\Support\Reports\Charts\ChartSeries;
use App\Support\Reports\Charts\ChartSeriesKind;
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\Charts\ChartType;
use App\Support\Reports\Charts\ChartWidth;
use App\Support\Reports\Charts\MonthSpine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ChartSpecTest extends TestCase
{
    public function test_validation_errors(): void
    {
        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'bare',
                type: ChartType::Area,
                titleKey: 't',
                descriptionKey: null,
                categories: ['2026-01'],
                categoryKind: CategoryKind::Month,
                series: [ChartSeries::keyed('k', ['1.00'])],
                format: ChartFormat::Money,
            ),
            InvalidArgumentException::class,
            'requires a currency',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'combo',
                type: ChartType::Combo,
                titleKey: 't',
                descriptionKey: null,
                categories: ['2026-01'],
                categoryKind: CategoryKind::Month,
                series: [ChartSeries::keyed('k', [1], ChartSeriesKind::Column, 0)],
                format: ChartFormat::Int,
                secondaryFormat: ChartFormat::Money,
            ),
            InvalidArgumentException::class,
            'requires a currency',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'donut',
                type: ChartType::Donut,
                titleKey: 't',
                descriptionKey: null,
                categories: ['a', 'b'],
                categoryKind: CategoryKind::Text,
                series: [
                    ChartSeries::keyed('one', [1, 2]),
                    ChartSeries::keyed('two', [3, 4]),
                ],
                format: ChartFormat::Int,
            ),
            InvalidArgumentException::class,
            'exactly one series',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'funnel',
                type: ChartType::Funnel,
                titleKey: 't',
                descriptionKey: null,
                categories: ['a'],
                categoryKind: CategoryKind::Text,
                series: [
                    ChartSeries::named('one', [1]),
                    ChartSeries::named('two', [1]),
                ],
                format: ChartFormat::Int,
            ),
            InvalidArgumentException::class,
            'exactly one series',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'len',
                type: ChartType::Column,
                titleKey: 't',
                descriptionKey: null,
                categories: ['a', 'b'],
                categoryKind: CategoryKind::Text,
                series: [ChartSeries::keyed('k', [1])],
                format: ChartFormat::Int,
            ),
            InvalidArgumentException::class,
            'series length',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'axis',
                type: ChartType::Column,
                titleKey: 't',
                descriptionKey: null,
                categories: ['a'],
                categoryKind: CategoryKind::Text,
                series: [ChartSeries::keyed('k', [1], null, 1)],
                format: ChartFormat::Int,
            ),
            InvalidArgumentException::class,
            'secondary axis',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'secondary',
                type: ChartType::Line,
                titleKey: 't',
                descriptionKey: null,
                categories: ['a'],
                categoryKind: CategoryKind::Text,
                series: [ChartSeries::keyed('k', [1])],
                format: ChartFormat::Int,
                secondaryFormat: ChartFormat::Percent,
            ),
            InvalidArgumentException::class,
            'secondary format',
        );

        $this->assertThrows(
            fn () => new ChartSpec(
                key: 'none',
                type: ChartType::Line,
                titleKey: 't',
                descriptionKey: null,
                categories: [],
                categoryKind: CategoryKind::Text,
                series: [],
                format: ChartFormat::Int,
            ),
            InvalidArgumentException::class,
            'at least one series',
        );

        $this->assertThrows(
            fn () => ChartSeries::keyed('', []),
            InvalidArgumentException::class,
            'exactly one',
        );

        $this->assertThrows(
            fn () => new ChartSeries([1], 'key', 'name'),
            InvalidArgumentException::class,
            'exactly one',
        );
    }

    public function test_to_array_shape_and_empty(): void
    {
        $empty = new ChartSpec(
            key: 'revpam:EUR',
            type: ChartType::Area,
            titleKey: 'insights.charts.revpam.title',
            descriptionKey: 'insights.charts.revpam.description',
            categories: ['2026-01'],
            categoryKind: CategoryKind::Month,
            series: [ChartSeries::keyed('insights.charts.series.revpam', ['0.00'])],
            format: ChartFormat::Money,
            currency: 'eur',
            width: ChartWidth::Half,
        );
        $payload = $empty->toArray();
        $this->assertSame('revpam:EUR', $payload['key']);
        $this->assertSame('area', $payload['type']);
        $this->assertSame('month', $payload['category_kind']);
        $this->assertSame('money', $payload['format']);
        $this->assertNull($payload['secondary_format']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame([], $payload['targets']);
        $this->assertFalse($payload['percent_stacked']);
        $this->assertSame('half', $payload['width']);
        $this->assertTrue($payload['empty']);
        $this->assertSame(0.0, $payload['series'][0]['data'][0]);
        $this->assertSame('insights.charts.series.revpam', $payload['series'][0]['name_key']);
        $this->assertNull($payload['series'][0]['name']);

        $filled = new ChartSpec(
            key: 'mix:GBP',
            type: ChartType::Donut,
            titleKey: 't',
            descriptionKey: null,
            categories: ['a'],
            categoryKind: CategoryKind::LabelKey,
            series: [ChartSeries::named('Total', ['12.50'])],
            format: ChartFormat::Money,
            currency: 'GBP',
        );
        $this->assertFalse($filled->toArray()['empty']);
        $this->assertSame(12.5, $filled->toArray()['series'][0]['data'][0]);
        $this->assertNull($filled->toArray()['series'][0]['name_key']);
    }

    public function test_month_spine(): void
    {
        $this->assertSame(
            ['2026-01', '2026-02'],
            MonthSpine::months('2026-02-15', '2026-01-03'),
        );
        $this->assertCount(12, MonthSpine::months('2026-06-15'));
        $this->assertSame('2024-07', MonthSpine::months('2026-06-15', '2020-01-01')[0]);
        $this->assertCount(24, MonthSpine::months('2026-06-15', '2020-01-01'));
        $this->assertSame('2026-06-01', MonthSpine::firstDay('2026-06'));
        $this->assertSame('2026-06-30', MonthSpine::lastDay('2026-06'));
        $this->assertSame('2026-06', MonthSpine::of('2026-06-15'));
        $this->assertSame(17, MonthSpine::monthsBetween('2025-01-01', '2026-06-15'));
        $this->assertSame(0, MonthSpine::monthsBetween('2026-01-15', '2026-02-14'));
    }

    private function assertThrows(callable $fn, string $class, string $message): void
    {
        try {
            $fn();
            $this->fail('Expected '.$class);
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
