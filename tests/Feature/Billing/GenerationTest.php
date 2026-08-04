<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunItemOutcome;
use App\Enums\BillingRunTrigger;
use App\Enums\ChargeType;
use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Enums\MoveOutSettlement;
use App\Models\BillingRunItem;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Billing\BillingMath;
use App\Support\Billing\BillingRunEngine;
use App\Support\Billing\RecurringBilling;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class GenerationTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private UnitClassRate $rate;

    private Price $cataloguePrice;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $entity->id,
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [$this->rate, $this->cataloguePrice] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->unitClass->update(['current_price_id' => $this->cataloguePrice->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '0.00',
            moveOutSettlement: MoveOutSettlement::None->value,
            turnoverHoldDays: 0,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_period_charges_and_invoice(): void
    {
        $contract = $this->makeContract(
            billedThrough: '2026-07-15',
            amount: '100.00',
            taxSnapshot: '21.00',
        );

        $run = (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);

        $this->assertSame(1, $run->contracts_billed);

        $charges = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Rent)
            ->where('due_date', '2026-07-15')
            ->get();
        $this->assertCount(1, $charges);

        $charge = $charges->first();
        $expected = BillingMath::applyTax('100.00', '21.00');
        $this->assertSame($expected->net, (string) $charge->net_amount);
        $this->assertSame($expected->tax, (string) $charge->tax_amount);
        $this->assertSame($expected->gross, (string) $charge->amount);
        $this->assertSame('2026-07-15', $charge->period_start?->toDateString());
        $this->assertSame('2026-08-15', $charge->period_end?->toDateString());
        $this->assertSame('2026-07-15', $charge->due_date?->toDateString());
        $this->assertNotNull($charge->invoice_id);

        $invoice = Invoice::query()->findOrFail($charge->invoice_id);
        $this->assertCount(1, $invoice->lines);
        $this->assertSame((int) $charge->id, (int) $invoice->lines->first()->charge_id);
    }

    public function test_s02_payoff_rate_change_straddle(): void
    {
        $source = file_get_contents(app_path('Support/Billing/RecurringBilling.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('rate_change', $source);
        $this->assertStringNotContainsString('effective_to', $source);
        $this->assertStringNotContainsString('supersedes', $source);

        $engineSource = file_get_contents(app_path('Support/Billing/BillingRunEngine.php'));
        $this->assertIsString($engineSource);
        $this->assertStringNotContainsString('rate_change', $engineSource);

        $contract = $this->makeContract(billedThrough: '2026-07-15', amount: '100.00');
        $item = $contract->items()->whereNull('effective_to')->firstOrFail();

        $newPrice = Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $this->rate->id,
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => '150.00',
            'currency' => 'EUR',
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $item->forceFill(['effective_to' => '2026-08-15'])->save();
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $newPrice->id,
            'effective_from' => '2026-08-15',
            'effective_to' => null,
            'supersedes_id' => $item->id,
            'change_reason' => ContractItemChangeReason::RateChange,
        ]);

        (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);

        $july = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('period_start', '2026-07-15')
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();
        $august = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('period_start', '2026-08-15')
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $this->assertSame('100.00', (string) $july->net_amount);
        $this->assertSame('150.00', (string) $august->net_amount);
    }

    public function test_stop_line_never_writes_cursor(): void
    {
        // Past stop: pre-check skip, cursor untouched.
        $past = $this->makeContract(billedThrough: '2026-08-15', amount: '100.00');
        $past->forceFill([
            'status' => ContractStatus::NoticeGiven,
            'notice_given_on' => '2026-07-01',
            'notice_period_days' => 14,
            'scheduled_move_out_on' => '2026-07-20',
        ])->save();
        $cursorBefore = $past->billedThrough();

        $run = (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $past->id);
        $item = BillingRunItem::query()->where('billing_run_id', $run->id)->firstOrFail();
        $this->assertSame(BillingRunItemOutcome::Skipped, $item->outcome);
        $this->assertSame('stop_line', $item->detail);
        $past->refresh();
        $this->assertSame($cursorBefore, $past->billedThrough());
        $this->assertSame(0, Charge::query()->where('contract_id', $past->id)->count());

        // Straddle: period that starts before stop bills in full; loop halts; cursor = last billed end.
        $straddle = $this->makeContract(billedThrough: '2026-07-15', amount: '100.00');
        $straddle->forceFill([
            'status' => ContractStatus::NoticeGiven,
            'notice_given_on' => '2026-07-20',
            'notice_period_days' => 14,
            // stop = max(2026-08-10, 2026-08-03) = 2026-08-10
            'scheduled_move_out_on' => '2026-08-10',
        ])->save();
        $stop = RecurringBilling::stopDate($straddle->fresh());
        $this->assertNotNull($stop);
        $this->assertSame('2026-08-10', $stop->toDateString());

        $run2 = (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $straddle->id);
        $this->assertSame(1, $run2->contracts_billed);

        $straddle->refresh();
        // Jul15→Aug15 straddles stop Aug10 and bills full; Aug15≥stop so loop halts.
        $this->assertSame('2026-08-15', $straddle->billedThrough());
        $this->assertSame(
            1,
            Charge::query()->where('contract_id', $straddle->id)->where('charge_type', ChargeType::Rent)->count()
        );
        $this->assertNotSame($stop->toDateString(), $straddle->billedThrough());
    }

    public function test_notice_withdrawal_resumes_catch_up_from_truthful_cursor(): void
    {
        $contract = $this->signViaApi(amount: '100.00', moveIn: '2026-01-15');
        $contract->forceFill([
            'billed_through' => '2026-08-15',
            'notice_period_days' => 14,
            'status' => ContractStatus::NoticeGiven,
            'notice_given_on' => '2026-07-01',
            'scheduled_move_out_on' => '2026-07-20',
        ])->save();
        $cursorBefore = $contract->billedThrough();

        (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);
        $contract->refresh();
        $this->assertSame($cursorBefore, $contract->billedThrough());

        $this->postJson("/api/contracts/{$contract->id}/notice-withdraw")->assertOk();
        $contract->refresh();
        $this->assertSame(ContractStatus::Active, $contract->status);
        $this->assertSame($cursorBefore, $contract->billedThrough());

        // With horizon still today (Aug 15) and cursor Aug 15, next period starts Aug 15 ≤ horizon.
        $run = (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);
        $this->assertSame(1, $run->contracts_billed);
        $contract->refresh();
        $this->assertSame('2026-09-15', $contract->billedThrough());
    }

    public function test_vacate_gap_charge_agrees_with_cursor(): void
    {
        foreach ([MoveOutSettlement::None, MoveOutSettlement::Daily] as $policy) {
            Setting::setBilling(Setting::billing()->with(
                defaultDepositAmount: '0.00',
                moveOutSettlement: $policy->value,
                turnoverHoldDays: 0,
            ));

            $unit = Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $this->unitClass->id,
            ]);

            $response = $this->postJson('/api/contracts', [
                'contact_id' => Contact::factory()->create()->id,
                'start_date' => '2026-06-15',
                'move_in_date' => '2026-06-15',
                'deposit_amount' => '0.00',
                'items' => [[
                    'item_type' => 'unit',
                    'item_id' => $unit->id,
                    'amount' => '100.00',
                ]],
            ])->assertCreated();

            $contract = Contract::query()->findOrFail($response->json('data.id'));
            $contract->forceFill([
                'billed_through' => '2026-07-15',
                'notice_period_days' => 0,
                'move_out_settlement' => $policy,
            ])->save();

            $this->postJson("/api/contracts/{$contract->id}/notice", [
                'scheduled_move_out_on' => '2026-08-20',
            ])->assertOk();

            (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);
            $contract->refresh();
            $cursorAfterRun = $contract->billedThrough();
            // Jul15→Aug15 bills (straddles stop Aug20? start Jul15 < Aug20); Aug15→Sep15 start < Aug20 bills;
            // Sep15 >= Aug20 halt. Cursor at Sep15.
            $this->assertSame('2026-09-15', $cursorAfterRun);

            $chargesBeforeVacate = Charge::query()->where('contract_id', $contract->id)->pluck('id');

            $this->postJson("/api/contracts/{$contract->id}/vacate", [
                'move_out_on' => '2026-08-20',
                'deposit' => ['outcome' => 'released'],
            ])->assertOk();

            $contract->refresh();
            $this->assertSame($cursorAfterRun, $contract->billedThrough());

            $vacateCharges = Charge::query()
                ->where('contract_id', $contract->id)
                ->whereNotIn('id', $chargesBeforeVacate)
                ->where('charge_type', ChargeType::Adjustment)
                ->get();

            // Cursor past final billing date → daily credits unused tail; none may still credit/gap per policy.
            if ($policy === MoveOutSettlement::Daily) {
                $this->assertTrue(
                    $vacateCharges->contains(fn (Charge $c) => str_starts_with((string) $c->description, 'vacate.credit')),
                    'Expected vacate credit when cursor is past final billing date under daily policy',
                );
            }

            $plan = \App\Support\Billing\VacateSettlement::compute(
                $contract->fresh(['items.price', 'charges']),
                CarbonImmutable::parse('2026-08-20'),
                \App\Enums\DepositSettlementOutcome::Released,
                [],
                CarbonImmutable::parse((string) $contract->notice_given_on),
            );
            $this->assertSame($cursorAfterRun, $plan['billed_through']);
        }
    }

    public function test_fiscal_blocker_atomic_retryable(): void
    {
        $contact = Contact::factory()->create(); // fiscally incomplete
        $contract = $this->makeContract(
            billedThrough: '2026-07-15',
            amount: '500.00',
            contact: $contact,
        );
        $cursorBefore = $contract->billedThrough();
        $chargeCountBefore = Charge::query()->where('contract_id', $contract->id)->count();

        $run = (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);
        $this->assertSame(1, $run->contracts_failed);

        $item = BillingRunItem::query()->where('billing_run_id', $run->id)->firstOrFail();
        $this->assertSame(BillingRunItemOutcome::Failed, $item->outcome);
        $this->assertSame('fiscal_blocker', $item->detail);

        $contract->refresh();
        $this->assertSame($cursorBefore, $contract->billedThrough());
        $this->assertSame(
            $chargeCountBefore,
            Charge::query()->where('contract_id', $contract->id)->count()
        );

        $complete = Contact::factory()->fiscalComplete()->create();
        $contract->forceFill(['contact_id' => $complete->id])->save();

        $retry = (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);
        $this->assertSame(1, $retry->contracts_billed);
        $contract->refresh();
        $this->assertNotSame($cursorBefore, $contract->billedThrough());
        $this->assertGreaterThan(
            $chargeCountBefore,
            Charge::query()->where('contract_id', $contract->id)->count()
        );
    }

    public function test_currency_mismatch_isolated(): void
    {
        $healthy = $this->makeContract(billedThrough: '2026-07-15', amount: '100.00');
        $poisoned = $this->makeContract(billedThrough: '2026-07-15', amount: '100.00');

        $insurance = Insurance::query()->create([
            'name' => 'GBP Cover',
            'coverage' => '5000.00',
            'currency' => 'GBP',
        ]);
        $gbp = Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $this->rate->id,
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => '10.00',
            'currency' => 'GBP',
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);
        ContractItem::query()->create([
            'contract_id' => $poisoned->id,
            'item_type' => 'insurance',
            'item_id' => $insurance->id,
            'price_id' => $gbp->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
        ]);

        $run = (new BillingRunEngine)->run(BillingRunTrigger::Manual);

        $failed = BillingRunItem::query()
            ->where('billing_run_id', $run->id)
            ->where('contract_id', $poisoned->id)
            ->firstOrFail();
        $this->assertSame(BillingRunItemOutcome::Failed, $failed->outcome);
        $this->assertSame('currency_mismatch', $failed->detail);

        $billed = BillingRunItem::query()
            ->where('billing_run_id', $run->id)
            ->where('contract_id', $healthy->id)
            ->firstOrFail();
        $this->assertSame(BillingRunItemOutcome::Billed, $billed->outcome);

        $poisoned->refresh();
        $this->assertSame('2026-07-15', $poisoned->billedThrough());
    }

    public function test_insurance_items_billed(): void
    {
        $contract = $this->makeContract(billedThrough: '2026-07-15', amount: '100.00');
        $insurance = Insurance::query()->create([
            'name' => 'Basic Cover',
            'coverage' => '1000.00',
            'currency' => 'EUR',
        ]);
        [, $insPrice] = $this->createInsuranceCataloguePrice(
            $insurance->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '15.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'insurance',
            'item_id' => $insurance->id,
            'price_id' => $insPrice->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
            'tax_rate_snapshot' => null,
        ]);

        (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contract->id);

        $rent = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Rent)
            ->where('period_start', '2026-07-15')
            ->first();
        $ins = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Insurance)
            ->where('period_start', '2026-07-15')
            ->first();

        $this->assertNotNull($rent);
        $this->assertNotNull($ins);
        $this->assertSame('15.00', (string) $ins->net_amount);
        $this->assertSame($rent->invoice_id, $ins->invoice_id);
    }

    /**
     * @return Contract
     */
    private function makeContract(
        string $billedThrough,
        string $amount = '100.00',
        ?string $taxSnapshot = null,
        ?Contact $contact = null,
    ): Contract {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $price = $this->cataloguePrice;
        if ($amount !== (string) $this->cataloguePrice->amount) {
            $price = Price::query()->create([
                'priceable_type' => 'unit_class_rate',
                'priceable_id' => $this->rate->id,
                'scope' => Price::SCOPE_CONTRACT,
                'amount' => $amount,
                'currency' => 'EUR',
                'effective_from' => null,
                'effective_to' => null,
                'created_by' => $this->employee->id,
            ]);
        }

        $contract = Contract::factory()->create([
            'contact_id' => ($contact ?? Contact::factory()->create())->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => '2026-01-15',
            'move_in_date' => '2026-01-15',
            'billed_through' => $billedThrough,
            'start_date' => '2026-01-15',
            'deposit_amount' => '0.00',
            'notice_period_days' => 14,
            'move_out_settlement' => MoveOutSettlement::None,
        ]);

        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
            'tax_rate_snapshot' => $taxSnapshot,
        ]);

        return $contract;
    }

    private function signViaApi(string $amount, string $moveIn): Contract
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $response = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => $amount,
            ]],
        ])->assertCreated();

        return Contract::query()->findOrFail($response->json('data.id'));
    }
}
