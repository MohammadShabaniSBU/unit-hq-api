<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Models\Contract;
use App\Support\Billing\BillingMath;
use App\Support\Billing\Exceptions\CatchUpCapExceeded;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PeriodsBetweenTest extends TestCase
{
    public function test_sequence_and_cap(): void
    {
        $contract = new Contract([
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => '2024-01-01',
        ]);

        $cursor = CarbonImmutable::parse('2024-01-01');

        $none = BillingMath::periodsBetween(
            $contract,
            $cursor,
            CarbonImmutable::parse('2023-12-31'),
            12,
        );
        $this->assertSame([], $none);

        $one = BillingMath::periodsBetween(
            $contract,
            $cursor,
            CarbonImmutable::parse('2024-01-01'),
            12,
        );
        $this->assertCount(1, $one);
        $this->assertSame('2024-01-01', $one[0]['start']->toDateString());
        $this->assertSame('2024-02-01', $one[0]['end']->toDateString());

        $three = BillingMath::periodsBetween(
            $contract,
            $cursor,
            CarbonImmutable::parse('2024-03-01'),
            12,
        );
        $this->assertCount(3, $three);
        $this->assertSame('2024-01-01', $three[0]['start']->toDateString());
        $this->assertSame('2024-02-01', $three[0]['end']->toDateString());
        $this->assertSame('2024-02-01', $three[1]['start']->toDateString());
        $this->assertSame('2024-03-01', $three[1]['end']->toDateString());
        $this->assertSame('2024-03-01', $three[2]['start']->toDateString());
        $this->assertSame('2024-04-01', $three[2]['end']->toDateString());

        try {
            BillingMath::periodsBetween(
                $contract,
                $cursor,
                CarbonImmutable::parse('2024-06-01'),
                3,
            );
            $this->fail('Expected CatchUpCapExceeded');
        } catch (CatchUpCapExceeded $e) {
            $this->assertSame(4, $e->count);
        }
    }
}
