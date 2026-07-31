<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Models\Allocation;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LedgerCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_currency_must_match_contract(): void
    {
        $contract = Contract::factory()->create(['currency' => 'EUR']);

        $this->expectException(ValidationException::class);

        Payment::query()->create([
            'contract_id' => $contract->id,
            'amount' => '50.00',
            'currency' => 'GBP',
            'idempotency_key' => 'test-mismatch-payment',
        ]);
    }

    public function test_allocation_across_currencies_rejected(): void
    {
        $eurContract = Contract::factory()->create(['currency' => 'EUR']);
        $gbpContract = Contract::factory()->create(['currency' => 'GBP']);

        $charge = Charge::query()->create([
            'contract_id' => $eurContract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => now()->toDateString(),
        ]);

        $payment = Payment::query()->create([
            'contract_id' => $gbpContract->id,
            'amount' => '100.00',
            'currency' => 'GBP',
            'idempotency_key' => 'test-cross-alloc',
        ]);

        $this->expectException(ValidationException::class);

        Allocation::query()->create([
            'payment_id' => $payment->id,
            'charge_id' => $charge->id,
            'amount' => '50.00',
        ]);
    }

    public function test_balance_computed_within_one_currency(): void
    {
        $contract = Contract::factory()->create(['currency' => 'EUR']);

        Charge::query()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => now()->toDateString(),
        ]);

        Payment::query()->create([
            'contract_id' => $contract->id,
            'amount' => '40.00',
            'currency' => 'EUR',
            'idempotency_key' => 'test-balance',
        ]);

        $summary = $contract->fresh()->billingSummary();

        $this->assertSame('60.00', $summary['balance_owed']);
        $this->assertSame('EUR', $summary['currency']);
    }
}
