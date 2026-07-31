<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Enums\TransferBilling;
use App\Enums\TransferPricingMode;
use App\Models\Activity;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractTransfer;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $originUnit;

    private Unit $destinationUnit;

    private Price $originPrice;

    private Price $destinationPrice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);

        $originClass = UnitClass::factory()->create(['code' => 'S5', 'tax_rate_code' => null]);
        [, $this->originPrice] = $this->createUnitClassCataloguePrice(
            $originClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '310.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $originClass->update(['current_price_id' => $this->originPrice->id]);
        $this->originUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $originClass->id,
        ]);

        $destClass = UnitClass::factory()->create(['code' => 'S15', 'tax_rate_code' => null]);
        [, $this->destinationPrice] = $this->createUnitClassCataloguePrice(
            $destClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '450.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $destClass->update(['current_price_id' => $this->destinationPrice->id]);
        $this->destinationUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $destClass->id,
        ]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '100.00',
            transferBilling: TransferBilling::ProrateImmediately->value,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_single_contract_two_occupancies(): void
    {
        $contract = $this->signContract('2026-06-01');
        $contractId = $contract->id;

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $contract->refresh();
        $this->assertSame($contractId, $contract->id);
        $this->assertSame(ContractStatus::Active, $contract->status);

        $occupancies = UnitOccupancy::query()
            ->where('contract_id', $contract->id)
            ->orderBy('started_on')
            ->get();
        $this->assertCount(2, $occupancies);
        $this->assertSame($this->originUnit->id, $occupancies[0]->unit_id);
        $this->assertSame('2026-07-20', $occupancies[0]->ended_on?->toDateString());
        $this->assertSame('transferred_out', $occupancies[0]->ended_reason);
        $this->assertSame($this->destinationUnit->id, $occupancies[1]->unit_id);
        $this->assertSame('2026-07-20', $occupancies[1]->started_on->toDateString());
        $this->assertNull($occupancies[1]->ended_on);

        $this->assertSame(1, ContractTransfer::query()->where('contract_id', $contract->id)->count());
    }

    public function test_item_version_superseded_not_updated(): void
    {
        $contract = $this->signContract('2026-06-01');
        $origin = $contract->unitItem;
        $this->assertNotNull($origin);
        $originAmount = (string) $origin->price->amount;
        $originPriceId = $origin->price_id;

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $origin->refresh();
        $this->assertSame($originAmount, (string) $origin->price->amount);
        $this->assertSame($originPriceId, $origin->price_id);
        $this->assertSame('2026-07-20', $origin->effective_to?->toDateString());

        $successor = ContractItem::query()
            ->where('supersedes_id', $origin->id)
            ->first();
        $this->assertNotNull($successor);
        $this->assertSame(ContractItemChangeReason::Transfer, $successor->change_reason);
        $this->assertSame($this->destinationUnit->id, $successor->item_id);
    }

    public function test_destination_must_be_available(): void
    {
        $contract = $this->signContract('2026-06-01');
        $this->signContractOnUnit($this->destinationUnit, '2026-06-01', '450.00');

        $chargeCount = Charge::query()->where('contract_id', $contract->id)->count();
        $itemCount = ContractItem::query()->where('contract_id', $contract->id)->count();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertStatus(422);

        $this->assertSame($chargeCount, Charge::query()->where('contract_id', $contract->id)->count());
        $this->assertSame($itemCount, ContractItem::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, ContractTransfer::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, UnitOccupancy::query()->where('contract_id', $contract->id)->count());
    }

    public function test_prorated_credit_uses_origin_tax_snapshot(): void
    {
        $contract = $this->prepareProrateContract();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $credit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Adjustment)
            ->where('description', 'like', 'transfer.credit%')
            ->first();

        $this->assertNotNull($credit);
        $this->assertSame('21.00', (string) $credit->tax_rate_snapshot);
    }

    public function test_prorated_amounts_net_correctly(): void
    {
        $contract = $this->prepareProrateContract();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
            'pricing_mode' => TransferPricingMode::DestinationRate->value,
        ])->assertOk();

        $days = BillingMath::daysBetween(
            CarbonImmutable::parse('2026-07-20'),
            CarbonImmutable::parse('2026-08-01'),
        );
        $daysInPeriod = BillingMath::daysBetween(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-08-01'),
        );

        $credit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('description', 'like', 'transfer.credit%')
            ->first();
        $debit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('description', 'transfer.debit')
            ->first();

        $this->assertNotNull($credit);
        $this->assertNotNull($debit);

        $expectedCreditNet = bcmul(BillingMath::prorate('310.00', $days, $daysInPeriod), '-1', 2);
        $expectedDebitNet = BillingMath::prorate('450.00', $days, $daysInPeriod);

        $this->assertSame($expectedCreditNet, (string) $credit->net_amount);
        $this->assertSame($expectedDebitNet, (string) $debit->net_amount);
    }

    public function test_next_period_mode_writes_no_adjustment(): void
    {
        $contract = $this->signContract('2026-06-01');
        $contract->forceFill([
            'transfer_billing' => TransferBilling::NextPeriod,
            'billed_through' => '2026-08-01',
        ])->save();

        $before = Charge::query()->where('contract_id', $contract->id)->count();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $this->assertSame(
            0,
            Charge::query()
                ->where('contract_id', $contract->id)
                ->where('description', 'like', 'transfer.%')
                ->count()
        );
        $this->assertSame($before, Charge::query()->where('contract_id', $contract->id)->count());
    }

    public function test_retain_rate_reuses_origin_price_id(): void
    {
        $contract = $this->signContract('2026-06-01');
        $originPriceId = (int) $contract->unitItem?->price_id;
        $pricesBefore = Price::query()->count();
        $junctionsBefore = UnitClassRate::query()->count();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
            'pricing_mode' => TransferPricingMode::RetainRate->value,
        ])->assertOk();

        $newItem = ContractItem::query()
            ->where('contract_id', $contract->id)
            ->whereNull('effective_to')
            ->where('item_type', 'unit')
            ->first();

        $this->assertNotNull($newItem);
        $this->assertSame($originPriceId, (int) $newItem->price_id);
        $this->assertSame($pricesBefore, Price::query()->count());
        $this->assertSame($junctionsBefore, UnitClassRate::query()->count());
    }

    public function test_destination_rate_references_catalogue_price(): void
    {
        $contract = $this->signContract('2026-06-01');
        $pricesBefore = Price::query()->count();
        $junctionsBefore = UnitClassRate::query()->count();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
            'pricing_mode' => TransferPricingMode::DestinationRate->value,
        ])->assertOk();

        $newItem = ContractItem::query()
            ->where('contract_id', $contract->id)
            ->whereNull('effective_to')
            ->where('item_type', 'unit')
            ->first();

        $this->assertNotNull($newItem);
        $this->assertSame($this->destinationPrice->id, $newItem->price_id);
        $this->assertSame($pricesBefore, Price::query()->count());
        $this->assertSame($junctionsBefore, UnitClassRate::query()->count());
    }

    public function test_transfer_writes_no_price_or_junction_row(): void
    {
        foreach ([TransferPricingMode::DestinationRate, TransferPricingMode::RetainRate] as $mode) {
            $origin = Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $this->originUnit->unit_class_id,
            ]);
            $dest = Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $this->destinationUnit->unit_class_id,
            ]);
            $contract = $this->signContractOnUnit($origin, '2026-06-01', '310.00');

            $pricesBefore = Price::query()->count();
            $junctionsBefore = UnitClassRate::query()->count();

            $this->postJson("/api/contracts/{$contract->id}/transfer", [
                'to_unit_id' => $dest->id,
                'transfer_date' => '2026-07-20',
                'pricing_mode' => $mode->value,
            ])->assertOk();

            $this->assertSame($pricesBefore, Price::query()->count());
            $this->assertSame($junctionsBefore, UnitClassRate::query()->count());
        }
    }

    public function test_deposit_shortfall_charged(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');
        Setting::setBilling(Setting::billing()->with(defaultDepositAmount: '250.00'));

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $contract->refresh();
        $this->assertSame('250.00', (string) $contract->deposit_amount);

        $deposit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Deposit)
            ->where('description', 'transfer.deposit_shortfall')
            ->first();

        $this->assertNotNull($deposit);
        $this->assertSame('150.00', (string) $deposit->net_amount);
    }

    public function test_insurance_items_untouched(): void
    {
        $insurance = Insurance::query()->create([
            'name' => 'Basic Cover',
            'coverage' => '1000.00',
            'currency' => 'EUR',
        ]);
        $this->createInsuranceCataloguePrice(
            $insurance->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '12.00', 'currency' => 'EUR'],
        );

        $contact = Contact::factory()->create();
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-06-01',
            'move_in_date' => '2026-06-01',
            'deposit_amount' => '100.00',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->originUnit->id,
                    'amount' => '310.00',
                ],
                [
                    'item_type' => 'insurance',
                    'item_id' => $insurance->id,
                    'amount' => '12.00',
                ],
            ],
        ]);
        $response->assertCreated();
        $contract = Contract::query()->findOrFail($response->json('data.id'));

        $insuranceItem = ContractItem::query()
            ->where('contract_id', $contract->id)
            ->where('item_type', 'insurance')
            ->first();
        $this->assertNotNull($insuranceItem);
        $insuranceSnapshot = $insuranceItem->only([
            'id', 'price_id', 'effective_from', 'effective_to', 'change_reason', 'tax_rate_snapshot',
        ]);

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $insuranceItem->refresh();
        $this->assertSame($insuranceSnapshot['id'], $insuranceItem->id);
        $this->assertSame($insuranceSnapshot['price_id'], $insuranceItem->price_id);
        $this->assertNull($insuranceItem->effective_to);
        $this->assertNull($insuranceItem->change_reason);
        $this->assertSame(
            1,
            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->where('item_type', 'insurance')
                ->count()
        );
    }

    public function test_preview_matches_commit(): void
    {
        $contract = $this->prepareProrateContract();
        Setting::setBilling(Setting::billing()->with(defaultDepositAmount: '250.00'));

        $body = [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
            'pricing_mode' => TransferPricingMode::DestinationRate->value,
        ];

        $preview = $this->postJson("/api/contracts/{$contract->id}/transfer-preview", $body)
            ->assertOk()
            ->json('data');

        $this->postJson("/api/contracts/{$contract->id}/transfer", $body)->assertOk();

        $contract->refresh()->load('charges');

        $credit = $contract->charges->first(
            fn (Charge $c) => str_starts_with((string) $c->description, 'transfer.credit')
        );
        $debit = $contract->charges->first(
            fn (Charge $c) => $c->description === 'transfer.debit'
        );
        $deposit = $contract->charges->first(
            fn (Charge $c) => $c->description === 'transfer.deposit_shortfall'
        );

        $this->assertSame($preview['credit']['gross'], (string) $credit?->amount);
        $this->assertSame($preview['credit']['net'], (string) $credit?->net_amount);
        $this->assertSame($preview['credit']['tax_rate_snapshot'], (string) $credit?->tax_rate_snapshot);
        $this->assertSame($preview['debit']['gross'], (string) $debit?->amount);
        $this->assertSame($preview['debit']['net'], (string) $debit?->net_amount);
        $this->assertSame($preview['deposit']['charge']['gross'], (string) $deposit?->amount);
        $this->assertSame($preview['deposit']['new_deposit_amount'], (string) $contract->deposit_amount);
        $this->assertSame($preview['resulting_balance'], $contract->balanceOwed());
        $this->assertSame($preview['destination_item']['price_id'], $contract->unitItem?->price_id);
    }

    public function test_billed_through_unchanged(): void
    {
        $contract = $this->prepareProrateContract();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $contract->refresh();
        $this->assertSame('2026-08-01', $contract->billedThrough());
    }

    public function test_forbidden_from_ended_contract(): void
    {
        $contract = $this->signContract('2026-06-01');
        $contract->forceFill(['status' => ContractStatus::Ended])->save();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertStatus(422);

        $this->assertSame(0, ContractTransfer::query()->where('contract_id', $contract->id)->count());
    }

    public function test_transfer_logs_core_activity(): void
    {
        $contract = $this->signContract('2026-06-01');

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
            'reason' => 'Upsize',
        ])->assertOk();

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'contract.transferred')
            ->where('subject_id', $contract->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->originUnit->id, $activity->properties->get('from_unit_id'));
        $this->assertSame($this->destinationUnit->id, $activity->properties->get('to_unit_id'));
        $this->assertSame('Upsize', $activity->properties->get('reason'));
        $this->assertIsString($activity->properties->get('credit'));
        $this->assertIsString($activity->properties->get('debit'));
    }

    private function prepareProrateContract(): Contract
    {
        $contract = $this->signContract('2026-06-01');
        $contract->forceFill([
            'billed_through' => '2026-08-01',
            'transfer_billing' => TransferBilling::ProrateImmediately,
        ])->save();

        Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $contract->unitItem?->id,
            'charge_type' => ChargeType::Rent,
            'period_start' => '2026-07-01',
            'period_end' => '2026-08-01',
            'net_amount' => '310.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '65.10',
            'amount' => '375.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
            'description' => 'Rent',
        ]);

        return $contract->fresh(['unitItem.price', 'charges']);
    }

    private function signContract(string $moveIn, string $deposit = '100.00'): Contract
    {
        return $this->signContractOnUnit($this->originUnit, $moveIn, '310.00', $deposit);
    }

    private function signContractOnUnit(
        Unit $unit,
        string $moveIn,
        string $amount,
        string $deposit = '100.00',
    ): Contract {
        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: $deposit,
            transferBilling: TransferBilling::ProrateImmediately->value,
        ));

        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'deposit_amount' => $deposit,
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $unit->id,
                    'amount' => $amount,
                ],
            ],
        ]);

        $response->assertCreated();

        return Contract::query()->findOrFail($response->json('data.id'));
    }
}
