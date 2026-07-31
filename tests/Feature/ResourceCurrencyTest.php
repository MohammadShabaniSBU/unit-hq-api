<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Http\Resources\BillingPeriodResource;
use App\Http\Resources\ChargeResource;
use App\Http\Resources\ContractResource;
use App\Http\Resources\PaymentResource;
use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ResourceCurrencyTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_every_money_field_has_a_currency(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
            ],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'deposit_amount' => '50.00',
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'base_rate' => '100.00',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ]);
        $period = BillingPeriod::query()->create([
            'contract_id' => $contract->id,
            'billing_period_start' => now()->startOfMonth()->toDateString(),
            'billing_period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $charge = Charge::query()->create([
            'contract_id' => $contract->id,
            'billing_period_id' => $period->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => now()->toDateString(),
        ]);
        $payment = Payment::query()->create([
            'contract_id' => $contract->id,
            'amount' => '40.00',
            'currency' => 'EUR',
            'idempotency_key' => 'resource-currency-test',
        ]);

        $contract->load([
            'items.item',
            'billingPeriods.charges',
            'payments.allocations',
            'charges',
            'contact',
        ]);

        $request = Request::create('/');
        $contractPayload = ContractResource::make($contract)->toArray($request);
        $chargePayload = ChargeResource::make($charge)->toArray($request);
        $paymentPayload = PaymentResource::make($payment->load('allocations'))->toArray($request);
        $periodPayload = BillingPeriodResource::make($period->load(['charges', 'contract']))->toArray($request);

        $this->assertSame('EUR', $contractPayload['currency']);
        $this->assertSame('EUR', $contractPayload['items'][0]['currency']);
        $this->assertSame('EUR', $contractPayload['billing_summary']['currency']);
        $this->assertArrayHasKey('balance_owed', $contractPayload['billing_summary']);
        $this->assertSame('EUR', $chargePayload['currency']);
        $this->assertArrayHasKey('amount', $chargePayload);
        $this->assertSame('EUR', $paymentPayload['currency']);
        $this->assertArrayHasKey('amount', $paymentPayload);
        $this->assertSame('EUR', $periodPayload['currency']);
        $this->assertArrayHasKey('total', $periodPayload);
    }
}
