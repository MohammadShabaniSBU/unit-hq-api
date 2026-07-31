<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Billing;

use App\Models\Charge;
use App\Models\Contract;
use App\Models\Payment;
use App\Support\Billing\CurrencyGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurrencyGuardTest extends TestCase
{
    public function test_assert_items_agree_returns_shared_currency(): void
    {
        $items = collect([
            (object) ['currency' => 'eur'],
            (object) ['currency' => 'EUR'],
        ]);

        $this->assertSame('EUR', CurrencyGuard::assertItemsAgree($items));
    }

    public function test_assert_items_agree_rejects_mixed_currencies(): void
    {
        $this->expectException(ValidationException::class);

        CurrencyGuard::assertItemsAgree(collect([
            (object) ['currency' => 'EUR'],
            (object) ['currency' => 'GBP'],
        ]));
    }

    public function test_assert_matches_contract_rejects_mismatch(): void
    {
        $contract = new Contract(['currency' => 'EUR']);

        $this->expectException(ValidationException::class);

        CurrencyGuard::assertMatchesContract($contract, 'GBP');
    }

    public function test_assert_allocatable_rejects_cross_currency(): void
    {
        $charge = new Charge(['currency' => 'EUR']);
        $payment = new Payment(['currency' => 'GBP']);

        $this->expectException(ValidationException::class);

        CurrencyGuard::assertAllocatable($charge, $payment);
    }

    public function test_rate_junction_hard_fails_unless_override(): void
    {
        $this->expectException(ValidationException::class);

        CurrencyGuard::assertRateJunction('GBP', 'EUR', false);
    }

    public function test_rate_junction_allows_override(): void
    {
        CurrencyGuard::assertRateJunction('GBP', 'EUR', true);

        $this->assertTrue(true);
    }

    public function test_rate_junction_skips_when_site_currency_unset(): void
    {
        CurrencyGuard::assertRateJunction(null, 'EUR', false);

        $this->assertTrue(true);
    }
}
