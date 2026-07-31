<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Billing;

use App\Enums\ChargeType;
use App\Support\Billing\RevenueByCurrency;
use PHPUnit\Framework\TestCase;

class RevenueByCurrencyTest extends TestCase
{
    public function test_revenue_is_grouped_by_currency(): void
    {
        $charges = [
            (object) ['charge_type' => ChargeType::Rent, 'amount' => '100.00', 'currency' => 'EUR'],
            (object) ['charge_type' => ChargeType::Insurance, 'amount' => '20.00', 'currency' => 'EUR'],
            (object) ['charge_type' => ChargeType::Rent, 'amount' => '80.00', 'currency' => 'GBP'],
            (object) ['charge_type' => ChargeType::Deposit, 'amount' => '200.00', 'currency' => 'EUR'],
            (object) ['charge_type' => ChargeType::WriteOff, 'amount' => '10.00', 'currency' => 'GBP'],
        ];

        $grouped = RevenueByCurrency::group($charges);

        $this->assertSame(['EUR' => '120.00', 'GBP' => '80.00'], $grouped);
        $this->assertArrayNotHasKey('total', $grouped);
    }
}
