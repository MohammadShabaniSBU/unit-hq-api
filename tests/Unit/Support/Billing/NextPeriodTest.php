<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Models\Contract;
use App\Support\Billing\BillingMath;
use App\Support\Billing\Exceptions\MisalignedCursor;
use App\Support\Billing\Exceptions\UnsupportedCadence;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class NextPeriodTest extends TestCase
{
    public function test_anniversary_monthly_golden(): void
    {
        $cases = [
            'jan_31_leap' => [
                'anchor' => '2024-01-31',
                'expected' => [
                    ['2024-01-31', '2024-02-29'],
                    ['2024-02-29', '2024-03-31'],
                    ['2024-03-31', '2024-04-30'],
                    ['2024-04-30', '2024-05-31'],
                    ['2024-05-31', '2024-06-30'],
                    ['2024-06-30', '2024-07-31'],
                    ['2024-07-31', '2024-08-31'],
                    ['2024-08-31', '2024-09-30'],
                    ['2024-09-30', '2024-10-31'],
                    ['2024-10-31', '2024-11-30'],
                    ['2024-11-30', '2024-12-31'],
                    ['2024-12-31', '2025-01-31'],
                ],
            ],
            'jan_31_non_leap' => [
                'anchor' => '2025-01-31',
                'expected' => [
                    ['2025-01-31', '2025-02-28'],
                    ['2025-02-28', '2025-03-31'],
                    ['2025-03-31', '2025-04-30'],
                    ['2025-04-30', '2025-05-31'],
                    ['2025-05-31', '2025-06-30'],
                    ['2025-06-30', '2025-07-31'],
                    ['2025-07-31', '2025-08-31'],
                    ['2025-08-31', '2025-09-30'],
                    ['2025-09-30', '2025-10-31'],
                    ['2025-10-31', '2025-11-30'],
                    ['2025-11-30', '2025-12-31'],
                    ['2025-12-31', '2026-01-31'],
                ],
            ],
            'jan_30_leap' => [
                'anchor' => '2024-01-30',
                'expected' => [
                    ['2024-01-30', '2024-02-29'],
                    ['2024-02-29', '2024-03-30'],
                    ['2024-03-30', '2024-04-30'],
                    ['2024-04-30', '2024-05-30'],
                    ['2024-05-30', '2024-06-30'],
                    ['2024-06-30', '2024-07-30'],
                    ['2024-07-30', '2024-08-30'],
                    ['2024-08-30', '2024-09-30'],
                    ['2024-09-30', '2024-10-30'],
                    ['2024-10-30', '2024-11-30'],
                    ['2024-11-30', '2024-12-30'],
                    ['2024-12-30', '2025-01-30'],
                ],
            ],
            'jan_29_leap' => [
                'anchor' => '2024-01-29',
                'expected' => [
                    ['2024-01-29', '2024-02-29'],
                    ['2024-02-29', '2024-03-29'],
                    ['2024-03-29', '2024-04-29'],
                    ['2024-04-29', '2024-05-29'],
                    ['2024-05-29', '2024-06-29'],
                    ['2024-06-29', '2024-07-29'],
                    ['2024-07-29', '2024-08-29'],
                    ['2024-08-29', '2024-09-29'],
                    ['2024-09-29', '2024-10-29'],
                    ['2024-10-29', '2024-11-29'],
                    ['2024-11-29', '2024-12-29'],
                    ['2024-12-29', '2025-01-29'],
                ],
            ],
        ];

        foreach ($cases as $label => $case) {
            $contract = $this->anniversaryContract($case['anchor'], BillingInterval::Month, 1);
            $this->assertConsecutivePeriods($contract, $case['expected'], $label);
        }
    }

    public function test_anniversary_weekly_x2_golden(): void
    {
        $expected = [
            ['2024-01-01', '2024-01-15'],
            ['2024-01-15', '2024-01-29'],
            ['2024-01-29', '2024-02-12'],
            ['2024-02-12', '2024-02-26'],
            ['2024-02-26', '2024-03-11'],
            ['2024-03-11', '2024-03-25'],
            ['2024-03-25', '2024-04-08'],
            ['2024-04-08', '2024-04-22'],
        ];

        $contract = $this->anniversaryContract('2024-01-01', BillingInterval::Week, 2);
        $this->assertConsecutivePeriods($contract, $expected, 'weekly_x2');
    }

    public function test_calendar_and_week_golden(): void
    {
        $day1 = $this->calendarContract('2024-01-01');
        $this->assertConsecutivePeriods($day1, [
            ['2024-01-01', '2024-02-01'],
            ['2024-02-01', '2024-03-01'],
            ['2024-03-01', '2024-04-01'],
            ['2024-04-01', '2024-05-01'],
            ['2024-05-01', '2024-06-01'],
            ['2024-06-01', '2024-07-01'],
            ['2024-07-01', '2024-08-01'],
            ['2024-08-01', '2024-09-01'],
        ], 'calendar_day_1');

        $day28 = $this->calendarContract('2024-01-28');
        $this->assertConsecutivePeriods($day28, [
            ['2024-01-28', '2024-02-28'],
            ['2024-02-28', '2024-03-28'],
            ['2024-03-28', '2024-04-28'],
            ['2024-04-28', '2024-05-28'],
            ['2024-05-28', '2024-06-28'],
            ['2024-06-28', '2024-07-28'],
            ['2024-07-28', '2024-08-28'],
            ['2024-08-28', '2024-09-28'],
        ], 'calendar_day_28');

        // 2024-01-01 is a Monday.
        $monday = $this->calendarWeekContract('2024-01-01');
        $this->assertConsecutivePeriods($monday, [
            ['2024-01-01', '2024-01-08'],
            ['2024-01-08', '2024-01-15'],
            ['2024-01-15', '2024-01-22'],
            ['2024-01-22', '2024-01-29'],
            ['2024-01-29', '2024-02-05'],
            ['2024-02-05', '2024-02-12'],
            ['2024-02-12', '2024-02-19'],
            ['2024-02-19', '2024-02-26'],
        ], 'calendar_week_monday');
    }

    public function test_no_drift_over_24_periods(): void
    {
        $anchor = CarbonImmutable::parse('2024-01-31')->startOfDay();
        $contract = $this->anniversaryContract('2024-01-31', BillingInterval::Month, 1);

        $cursor = $anchor;

        for ($n = 0; $n < 24; $n++) {
            $window = BillingMath::nextPeriod($contract, $cursor);
            $expectedStart = BillingMath::advancePeriod($anchor, BillingInterval::Month, $n);
            $expectedEnd = BillingMath::advancePeriod($anchor, BillingInterval::Month, $n + 1);

            $this->assertSame(
                $expectedStart->toDateString(),
                $window['start']->toDateString(),
                "start mismatch at period {$n}",
            );
            $this->assertSame(
                $expectedEnd->toDateString(),
                $window['end']->toDateString(),
                "end mismatch at period {$n}",
            );

            // Cursor-chained advance must not use addMonthsNoOverflow(cursor):
            // Jan 31 → Feb 29 → Mar 29 would diverge from Mar 31.
            $cursor = $window['end'];
        }
    }

    public function test_bad_cursor_throws(): void
    {
        $cases = [
            [$this->anniversaryContract('2024-01-31', BillingInterval::Month, 1), '2024-02-15'],
            // Leap year lattice has Feb 29, not Feb 28.
            [$this->anniversaryContract('2024-01-31', BillingInterval::Month, 1), '2024-02-28'],
            [$this->calendarContract('2024-01-01'), '2024-01-15'],
            [$this->calendarWeekContract('2024-01-01'), '2024-01-02'],
        ];

        foreach ($cases as [$contract, $cursor]) {
            try {
                BillingMath::nextPeriod($contract, CarbonImmutable::parse($cursor));
                $this->fail("Expected MisalignedCursor for cursor {$cursor}");
            } catch (MisalignedCursor) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unsupported_cadence_guard(): void
    {
        $contract = new Contract([
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 2,
            'billing_anchor_model' => BillingAnchorModel::Calendar,
            'billing_anchor_date' => '2024-01-01',
        ]);

        $this->expectException(UnsupportedCadence::class);
        BillingMath::nextPeriod($contract, CarbonImmutable::parse('2024-01-01'));
    }

    public function test_unsupported_cadence_guard_calendar_week(): void
    {
        $contract = new Contract([
            'billing_interval' => BillingInterval::Week,
            'billing_interval_count' => 2,
            'billing_anchor_model' => BillingAnchorModel::CalendarWeek,
            'billing_anchor_date' => '2024-01-01',
        ]);

        $this->expectException(UnsupportedCadence::class);
        BillingMath::nextPeriod($contract, CarbonImmutable::parse('2024-01-01'));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $expected
     */
    private function assertConsecutivePeriods(Contract $contract, array $expected, string $label): void
    {
        $cursor = CarbonImmutable::parse($expected[0][0])->startOfDay();

        foreach ($expected as $i => [$start, $end]) {
            $window = BillingMath::nextPeriod($contract, $cursor);

            $this->assertSame($start, $window['start']->toDateString(), "{$label}[{$i}].start");
            $this->assertSame($end, $window['end']->toDateString(), "{$label}[{$i}].end");

            $cursor = $window['end'];
        }
    }

    private function anniversaryContract(
        string $anchor,
        BillingInterval $interval,
        int $count,
    ): Contract {
        return new Contract([
            'billing_interval' => $interval,
            'billing_interval_count' => $count,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => $anchor,
        ]);
    }

    private function calendarContract(string $anchor): Contract
    {
        return new Contract([
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Calendar,
            'billing_anchor_date' => $anchor,
        ]);
    }

    private function calendarWeekContract(string $anchor): Contract
    {
        return new Contract([
            'billing_interval' => BillingInterval::Week,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::CalendarWeek,
            'billing_anchor_date' => $anchor,
        ]);
    }
}
