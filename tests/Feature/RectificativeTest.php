<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceSeriesKind;
use App\Enums\InvoiceStatus;
use App\Enums\MoveOutSettlement;
use App\Enums\TransferBilling;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Fiscal\InvoiceRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RectificativeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private Unit $destinationUnit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);

        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '310.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $destClass = UnitClass::factory()->create(['code' => 'S15']);
        [, $destPrice] = $this->createUnitClassCataloguePrice(
            $destClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '450.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $destClass->update(['current_price_id' => $destPrice->id]);
        $this->destinationUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $destClass->id,
        ]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '100.00',
            moveOutSettlement: MoveOutSettlement::Daily->value,
            transferBilling: TransferBilling::ProrateImmediately->value,
            turnoverHoldDays: 0,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_vacate_credits_grouped_per_original(): void
    {
        $contract = $this->signContract('2026-06-01');
        $rentInvoice = Invoice::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($rentInvoice);

        $rentCharge = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Rent)
            ->where('invoice_id', $rentInvoice->id)
            ->first();
        $this->assertNotNull($rentCharge);

        // Second open item + invoiced charge → second original for grouping.
        $insurance = Insurance::query()->create([
            'name' => 'Basic Cover',
            'coverage' => '1000.00',
            'currency' => 'EUR',
        ]);
        [, $insurancePrice] = $this->createInsuranceCataloguePrice(
            $insurance->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '20.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $insuranceItem = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'insurance',
            'item_id' => $insurance->id,
            'price_id' => $insurancePrice->id,
            'base_rate' => '20.00',
            'tax_rate_id' => $contract->unitItem?->tax_rate_id,
            'tax_rate_snapshot' => '21.00',
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        $insuranceCharge = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $insuranceItem->id,
            'charge_type' => ChargeType::Insurance,
            'period_start' => '2026-07-01',
            'period_end' => '2026-08-01',
            'net_amount' => '20.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '4.20',
            'amount' => '24.20',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
            'description' => 'Insurance',
        ]);

        $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
        $insuranceInvoice = DB::transaction(fn () => InvoiceIssuer::issue(
            $contract,
            collect([$insuranceCharge]),
            null,
            $this->employee->id,
        ));
        $this->assertNotNull($insuranceInvoice);
        $this->assertNotSame($rentInvoice->id, $insuranceInvoice->id);

        $contract->forceFill([
            'billed_through' => '2026-08-01',
            'notice_period_days' => 0,
        ])->save();

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $rectificatives = Invoice::query()
            ->where('contract_id', $contract->id)
            ->where('kind', InvoiceKind::Rectificative)
            ->get();

        $this->assertCount(2, $rectificatives);
        $this->assertEqualsCanonicalizing(
            [$rentInvoice->id, $insuranceInvoice->id],
            $rectificatives->pluck('rectifies_invoice_id')->all(),
        );

        foreach ($rectificatives as $rectificative) {
            $this->assertSame(InvoiceStatus::Issued, $rectificative->status);
            $this->assertSame(InvoiceIssuer::REASON_VACATE_SETTLEMENT, $rectificative->rectification_reason);
            $this->assertTrue(bccomp((string) $rectificative->gross_total, '0', 2) < 0);
            $series = InvoiceSeries::query()->find($rectificative->invoice_series_id);
            $this->assertSame(InvoiceSeriesKind::Rectificative, $series?->kind);
        }
    }

    public function test_transfer_issues_matched_pair(): void
    {
        $contract = $this->signContract('2026-06-01');
        $original = Invoice::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($original);

        $contract->forceFill(['billed_through' => '2026-08-01'])->save();

        $beforeIds = Invoice::query()->where('contract_id', $contract->id)->pluck('id')->all();

        $this->postJson("/api/contracts/{$contract->id}/transfer", [
            'to_unit_id' => $this->destinationUnit->id,
            'transfer_date' => '2026-07-20',
        ])->assertOk();

        $newInvoices = Invoice::query()
            ->where('contract_id', $contract->id)
            ->whereNotIn('id', $beforeIds)
            ->get();

        $rectificative = $newInvoices->first(
            fn (Invoice $i) => $i->kind === InvoiceKind::Rectificative
        );
        $ordinary = $newInvoices->first(
            fn (Invoice $i) => in_array($i->kind, [InvoiceKind::Ordinary, InvoiceKind::Simplified], true)
        );

        $this->assertNotNull($rectificative);
        $this->assertNotNull($ordinary);
        $this->assertSame($original->id, $rectificative->rectifies_invoice_id);
        $this->assertSame(InvoiceIssuer::REASON_TRANSFER_CREDIT, $rectificative->rectification_reason);
        $this->assertTrue(bccomp((string) $rectificative->gross_total, '0', 2) < 0);
        $this->assertTrue(bccomp((string) $ordinary->gross_total, '0', 2) > 0);

        $credit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('description', 'like', 'transfer.credit%')
            ->first();
        $this->assertNotNull($credit);
        $this->assertSame($rectificative->id, $credit->invoice_id);

        $debit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('description', 'transfer.debit')
            ->first();
        $this->assertNotNull($debit);
        $this->assertSame($ordinary->id, $debit->invoice_id);
    }

    public function test_wrong_series_kind_rejected(): void
    {
        $contract = $this->signContract('2026-06-01');
        $original = Invoice::query()->where('contract_id', $contract->id)->firstOrFail();
        $rent = Charge::query()
            ->where('invoice_id', $original->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $credit = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $rent->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'period_start' => '2026-07-20',
            'period_end' => '2026-08-01',
            'net_amount' => '-10.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-2.10',
            'amount' => '-12.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'vacate.credit #'.$rent->id,
            'reversal_of_charge_id' => $rent->id,
        ]);

        $ordinarySeries = InvoiceSeries::query()
            ->where('legal_entity_id', $original->legal_entity_id)
            ->where('kind', InvoiceSeriesKind::Ordinary)
            ->where('is_default', true)
            ->firstOrFail();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        DB::transaction(function () use ($original, $credit, $ordinarySeries): void {
            InvoiceIssuer::issueRectificative(
                $original,
                collect([$credit]),
                InvoiceIssuer::REASON_OPERATOR_CORRECTION,
                $this->employee->id,
                $ordinarySeries,
            );
        });
    }

    public function test_negative_totals_match_credit_charges(): void
    {
        $contract = $this->signContract('2026-06-01');
        $original = Invoice::query()->where('contract_id', $contract->id)->firstOrFail();
        $rent = Charge::query()
            ->where('invoice_id', $original->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $credit = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $rent->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'period_start' => '2026-07-20',
            'period_end' => '2026-08-01',
            'net_amount' => '-50.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-10.50',
            'amount' => '-60.50',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'vacate.credit #'.$rent->id,
            'reversal_of_charge_id' => $rent->id,
        ]);

        $rectificative = DB::transaction(fn () => InvoiceIssuer::issueRectificative(
            $original,
            collect([$credit]),
            InvoiceIssuer::REASON_VACATE_SETTLEMENT,
            $this->employee->id,
        ));

        $this->assertSame('-50.00', (string) $rectificative->net_total);
        $this->assertSame('-10.50', (string) $rectificative->tax_total);
        $this->assertSame('-60.50', (string) $rectificative->gross_total);

        $line = $rectificative->lines->first();
        $this->assertNotNull($line);
        $this->assertSame('21.00', (string) $line->tax_rate_snapshot);
        $this->assertSame('-60.50', (string) $line->gross_amount);
        $this->assertSame($credit->id, $line->charge_id);

        $payload = InvoiceRenderer::payloadFromInvoice($rectificative->fresh(['lines', 'rectifiesInvoice']));
        $html = InvoiceRenderer::html($payload);
        $this->assertSame($original->full_number, $payload['rectifies_full_number']);
        $this->assertStringContainsString($original->full_number, $html);
        $this->assertStringContainsString('-60.50', $html);
        $this->assertStringContainsString('negative', $html);
    }

    public function test_sweep_idempotent_and_reports_unmatched(): void
    {
        $contract = $this->signContract('2026-06-01');
        $original = Invoice::query()->where('contract_id', $contract->id)->firstOrFail();
        $rent = Charge::query()
            ->where('invoice_id', $original->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $matchable = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $rent->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'period_start' => '2026-07-20',
            'period_end' => '2026-08-01',
            'net_amount' => '-10.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-2.10',
            'amount' => '-12.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            // Historical shape: description only, no reversal FK.
            'description' => 'vacate.credit #'.$rent->id,
            'reversal_of_charge_id' => null,
        ]);

        $unmatched = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $rent->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'period_start' => '2026-07-20',
            'period_end' => '2026-08-01',
            'net_amount' => '-5.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-1.05',
            'amount' => '-6.05',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'orphan.credit',
            'reversal_of_charge_id' => null,
        ]);

        $beforeCount = Invoice::query()->where('kind', InvoiceKind::Rectificative)->count();

        Artisan::call('invoices:sweep-credits');
        $output = Artisan::output();

        $this->assertStringContainsString('Issued 1 rectificative', $output);
        $this->assertStringContainsString('unmatched', strtolower($output));
        $this->assertStringContainsString((string) $unmatched->id, $output);

        $matchable->refresh();
        $unmatched->refresh();
        $this->assertNotNull($matchable->invoice_id);
        $this->assertNull($unmatched->invoice_id);

        $this->assertSame(
            $beforeCount + 1,
            Invoice::query()->where('kind', InvoiceKind::Rectificative)->count()
        );

        Artisan::call('invoices:sweep-credits');
        $this->assertSame(
            $beforeCount + 1,
            Invoice::query()->where('kind', InvoiceKind::Rectificative)->count()
        );
        $this->assertNull($unmatched->fresh()->invoice_id);
    }

    public function test_rectifying_a_rectificative_allowed(): void
    {
        $contract = $this->signContract('2026-06-01');
        $original = Invoice::query()->where('contract_id', $contract->id)->firstOrFail();
        $rent = Charge::query()
            ->where('invoice_id', $original->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $credit1 = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $rent->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'period_start' => '2026-07-20',
            'period_end' => '2026-08-01',
            'net_amount' => '-10.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-2.10',
            'amount' => '-12.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'vacate.credit #'.$rent->id,
            'reversal_of_charge_id' => $rent->id,
        ]);

        $firstR = DB::transaction(fn () => InvoiceIssuer::issueRectificative(
            $original,
            collect([$credit1]),
            InvoiceIssuer::REASON_OPERATOR_CORRECTION,
            $this->employee->id,
        ));

        // Further correction of the first rectificative: credit that reverses a
        // charge on the rectificative itself (chain).
        $lineCharge = Charge::query()->findOrFail($firstR->lines->first()->charge_id);
        $credit2 = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $lineCharge->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'period_start' => '2026-07-20',
            'period_end' => '2026-08-01',
            'net_amount' => '-3.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-0.63',
            'amount' => '-3.63',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'operator.credit #'.$lineCharge->id,
            'reversal_of_charge_id' => $lineCharge->id,
        ]);

        $secondR = DB::transaction(fn () => InvoiceIssuer::issueRectificative(
            $firstR,
            collect([$credit2]),
            InvoiceIssuer::REASON_OPERATOR_CORRECTION,
            $this->employee->id,
        ));

        $this->assertSame($firstR->id, $secondR->rectifies_invoice_id);
        $this->assertSame(InvoiceKind::Rectificative, $secondR->kind);

        $show = $this->getJson("/api/invoices/{$firstR->id}")->assertOk();
        $this->assertSame($original->id, $show->json('data.rectifies_invoice.id'));
        $this->assertCount(1, $show->json('data.rectificatives'));
        $this->assertSame($secondR->id, $show->json('data.rectificatives.0.id'));
    }

    public function test_manual_rectify_eligibility(): void
    {
        $contract = $this->signContract('2026-06-01');
        $original = Invoice::query()->where('contract_id', $contract->id)->firstOrFail();
        $rent = Charge::query()
            ->where('invoice_id', $original->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $otherUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
        ]);
        $otherContract = $this->signContractOnUnit($otherUnit, '2026-06-01');

        $crossContract = Charge::query()->create([
            'contract_id' => $otherContract->id,
            'charge_type' => ChargeType::Adjustment,
            'net_amount' => '-10.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-2.10',
            'amount' => '-12.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'vacate.credit #'.$rent->id,
            'reversal_of_charge_id' => $rent->id,
        ]);

        $this->postJson("/api/invoices/{$original->id}/rectify", [
            'reason' => InvoiceIssuer::REASON_OPERATOR_CORRECTION,
            'charge_ids' => [$crossContract->id],
        ])->assertStatus(422);

        $positive = Charge::query()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Adjustment,
            'net_amount' => '10.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '2.10',
            'amount' => '12.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'positive.adj',
        ]);

        $this->postJson("/api/invoices/{$original->id}/rectify", [
            'reason' => InvoiceIssuer::REASON_OPERATOR_CORRECTION,
            'charge_ids' => [$positive->id],
        ])->assertStatus(422);

        $eligible = Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $rent->contract_item_id,
            'charge_type' => ChargeType::Adjustment,
            'net_amount' => '-10.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '-2.10',
            'amount' => '-12.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-20',
            'description' => 'vacate.credit #'.$rent->id,
            'reversal_of_charge_id' => $rent->id,
        ]);

        $this->postJson("/api/invoices/{$original->id}/rectify", [
            'reason' => InvoiceIssuer::REASON_OPERATOR_CORRECTION,
            'charge_ids' => [$eligible->id],
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'rectificative')
            ->assertJsonPath('data.rectifies_invoice_id', $original->id);

        // Already stamped — reject.
        $this->postJson("/api/invoices/{$original->id}/rectify", [
            'reason' => InvoiceIssuer::REASON_OPERATOR_CORRECTION,
            'charge_ids' => [$eligible->id],
        ])->assertStatus(422);
    }

    private function signContract(string $moveIn, string $deposit = '100.00'): Contract
    {
        return $this->signContractOnUnit($this->unit, $moveIn, $deposit);
    }

    private function signContractOnUnit(Unit $unit, string $moveIn, string $deposit = '100.00'): Contract
    {
        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: $deposit,
            moveOutSettlement: MoveOutSettlement::Daily->value,
            transferBilling: TransferBilling::ProrateImmediately->value,
            turnoverHoldDays: 0,
        ));

        $contact = Contact::factory()->fiscalComplete()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'deposit_amount' => $deposit,
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $unit->id,
                    'amount' => '310.00',
                ],
            ],
        ]);

        $response->assertCreated();

        return Contract::query()->findOrFail($response->json('data.id'));
    }
}
