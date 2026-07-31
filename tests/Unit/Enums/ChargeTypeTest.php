<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ChargeType;
use App\Support\Billing\RevenueByCurrency;
use PHPUnit\Framework\TestCase;
use ValueError;

class ChargeTypeTest extends TestCase
{
    public function test_write_off_refund_and_deposit_excluded_from_revenue(): void
    {
        $this->assertFalse(ChargeType::Deposit->isRevenue());
        $this->assertFalse(ChargeType::WriteOff->isRevenue());
        $this->assertFalse(ChargeType::Refund->isRevenue());

        $this->assertTrue(ChargeType::Rent->isRevenue());
        $this->assertTrue(ChargeType::Insurance->isRevenue());
        $this->assertTrue(ChargeType::LateFee->isRevenue());
        $this->assertTrue(ChargeType::Adjustment->isRevenue());
    }

    public function test_revenue_is_grouped_by_currency(): void
    {
        $grouped = RevenueByCurrency::group([
            (object) ['charge_type' => ChargeType::Rent, 'amount' => '50.00', 'currency' => 'EUR'],
            (object) ['charge_type' => ChargeType::Rent, 'amount' => '25.00', 'currency' => 'GBP'],
            (object) ['charge_type' => ChargeType::Deposit, 'amount' => '99.00', 'currency' => 'EUR'],
        ]);

        $this->assertSame(['EUR' => '50.00', 'GBP' => '25.00'], $grouped);
    }

    public function test_invalid_string_throws_on_from(): void
    {
        $this->expectException(ValueError::class);

        ChargeType::from('lateFee');
    }
}
