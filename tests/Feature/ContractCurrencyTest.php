<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Unit $unit;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();

        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $unitClass = UnitClass::factory()->create();
        $this->price = Price::query()->create([
            'amount' => '196.72',
            'currency' => 'EUR',
            'billing_period' => 'monthly',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);
        $unitClass->update(['current_price_id' => $this->price->id]);
        UnitClassRate::query()->create([
            'unit_class_id' => $unitClass->id,
            'site_id' => $site->id,
            'price_id' => $this->price->id,
        ]);
        $this->unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    public function test_currency_snapshotted_from_items_at_signing(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => now()->toDateString(),
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'currency' => 'EUR',
        ]);
        $this->assertDatabaseHas('contract_items', [
            'contract_id' => $contractId,
            'currency' => 'EUR',
            'amount' => '196.72',
        ]);
        $this->assertSame('EUR', $response->json('data.currency'));
    }

    public function test_mixed_currency_items_rejected(): void
    {
        $contact = Contact::factory()->create();
        $insurance = Insurance::query()->create([
            'name' => 'GBP Cover',
            'coverage' => '5000.00',
            'currency' => 'GBP',
        ]);

        $beforeContracts = Contract::query()->count();
        $beforeCharges = Charge::query()->count();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => now()->toDateString(),
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
                [
                    'item_type' => 'insurance',
                    'item_id' => $insurance->id,
                    'amount' => '5.00',
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['currency']);
        $this->assertSame($beforeContracts, Contract::query()->count());
        $this->assertSame($beforeCharges, Charge::query()->count());
    }

    public function test_item_snapshots_currency_alongside_amount(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => now()->toDateString(),
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $itemId = $response->json('data.items.0.id');

        $this->price->update(['effective_to' => now()->toDateString()]);
        Price::query()->create([
            'amount' => '250.00',
            'currency' => 'GBP',
            'billing_period' => 'monthly',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $item = ContractItem::query()->findOrFail($itemId);
        $this->assertSame('EUR', $item->currency);
        $this->assertSame('196.72', (string) $item->amount);
    }

    public function test_deposit_charge_carries_contract_currency(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => now()->toDateString(),
            'deposit_amount' => '100.00',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');

        $deposit = Charge::query()
            ->where('contract_id', $contractId)
            ->where('charge_type', ChargeType::Deposit)
            ->first();

        $this->assertNotNull($deposit);
        $this->assertSame('EUR', $deposit->currency);
        $this->assertNull($deposit->contract_item_id);
    }

    public function test_first_period_charges_carry_contract_currency(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => now()->toDateString(),
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');

        $currencies = Charge::query()
            ->where('contract_id', $contractId)
            ->pluck('currency')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['EUR'], $currencies);
    }

    public function test_superseded_price_does_not_change_signed_contract(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => now()->toDateString(),
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');

        $this->price->update(['effective_to' => now()->toDateString()]);
        $newPrice = Price::query()->create([
            'amount' => '999.00',
            'currency' => 'GBP',
            'billing_period' => 'monthly',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);
        UnitClassRate::query()->create([
            'unit_class_id' => $this->unit->unit_class_id,
            'site_id' => $this->unit->site_id,
            'price_id' => $newPrice->id,
        ]);

        $contract = Contract::query()->with('items')->findOrFail($contractId);
        $this->assertSame('EUR', $contract->currency);
        $this->assertSame('EUR', $contract->items->first()->currency);
        $this->assertSame('196.72', (string) $contract->items->first()->amount);
        $this->assertSame(ContractStatus::Active, $contract->status);
    }
}
