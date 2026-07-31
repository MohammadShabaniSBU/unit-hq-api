<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\InvoiceKind;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class InvoiceIssueTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_signing_issues_first_period_invoice(): void
    {
        [$unit, $contact] = $this->seedSigningContext(amount: '100.00');

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '50.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');

        $invoice = Invoice::query()->where('contract_id', $contractId)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceKind::Simplified, $invoice->kind);
        $this->assertSame('issued', $invoice->status->value);

        $lines = $invoice->lines;
        $this->assertCount(1, $lines);
        $this->assertSame(ChargeType::Rent, Charge::query()->find($lines->first()->charge_id)?->charge_type);

        $deposit = Charge::query()
            ->where('contract_id', $contractId)
            ->where('charge_type', ChargeType::Deposit)
            ->first();
        $this->assertNotNull($deposit);
        $this->assertNull($deposit->invoice_id);
    }

    public function test_deposit_charges_excluded_by_default(): void
    {
        config(['fiscal.invoice_deposits' => false]);

        [$unit, $contact] = $this->seedSigningContext(amount: '80.00');

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '200.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '80.00',
            ]],
        ])->assertCreated();

        $contractId = $response->json('data.id');
        $invoice = Invoice::query()->where('contract_id', $contractId)->firstOrFail();

        $this->assertTrue(
            $invoice->lines->every(fn ($line) => Charge::query()->find($line->charge_id)?->charge_type !== ChargeType::Deposit)
        );
    }

    public function test_kind_by_fiscal_completeness(): void
    {
        [$unitA, $incomplete] = $this->seedSigningContext(amount: '50.00');
        $unitB = Unit::factory()->create([
            'site_id' => $unitA->site_id,
            'unit_class_id' => $unitA->unit_class_id,
        ]);
        $complete = Contact::factory()->fiscalComplete()->create();

        $simplified = $this->postJson('/api/contracts', [
            'contact_id' => $incomplete->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unitA->id,
                'amount' => '50.00',
            ]],
        ])->assertCreated();

        $kind = Invoice::query()->where('contract_id', $simplified->json('data.id'))->firstOrFail()->kind;
        $this->assertSame('simplified', $kind instanceof InvoiceKind ? $kind->value : $kind);

        $ordinary = $this->postJson('/api/contracts', [
            'contact_id' => $complete->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unitB->id,
                'amount' => '50.00',
            ]],
        ])->assertCreated();

        $invoice = Invoice::query()->where('contract_id', $ordinary->json('data.id'))->firstOrFail();
        $this->assertSame('ordinary', $invoice->kind->value);
        $this->assertNotNull($invoice->buyer_tax_id);
        $this->assertNotNull($invoice->buyer_address);
    }

    public function test_simplified_over_limit_rejected(): void
    {
        [$unit, $contact] = $this->seedSigningContext(amount: '500.00');

        $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '500.00',
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_totals_match_lines_match_charges(): void
    {
        [$unit, $contact] = $this->seedSigningContext(amount: '120.00');
        $contact = Contact::factory()->fiscalComplete()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-01',
            'move_in_date' => '2026-07-01',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '120.00',
            ]],
        ])->assertCreated();

        $invoice = Invoice::query()->where('contract_id', $response->json('data.id'))->with('lines')->firstOrFail();
        $lineNet = '0.00';
        $lineTax = '0.00';
        $lineGross = '0.00';
        foreach ($invoice->lines as $line) {
            $lineNet = bcadd($lineNet, (string) $line->net_amount, 2);
            $lineTax = bcadd($lineTax, (string) $line->tax_amount, 2);
            $lineGross = bcadd($lineGross, (string) $line->gross_amount, 2);

            $charge = Charge::query()->findOrFail($line->charge_id);
            $this->assertSame((string) $charge->net_amount, (string) $line->net_amount);
            $this->assertSame((string) $charge->tax_amount, (string) $line->tax_amount);
            $this->assertSame((string) $charge->amount, (string) $line->gross_amount);
        }

        $this->assertSame($lineNet, (string) $invoice->net_total);
        $this->assertSame($lineTax, (string) $invoice->tax_total);
        $this->assertSame($lineGross, (string) $invoice->gross_total);
    }

    public function test_snapshots_immune_to_later_edits(): void
    {
        [$unit, $contact] = $this->seedSigningContext(amount: '90.00');
        $contact = Contact::factory()->fiscalComplete()->create([
            'billing_name' => 'Original Buyer',
            'billing_city' => 'Madrid',
        ]);
        $entity = LegalEntity::query()->findOrFail($unit->site->legal_entity_id);
        $originalIssuerName = $entity->legal_name;
        $originalIssuerTax = $entity->tax_id;

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '90.00',
            ]],
        ])->assertCreated();

        $invoice = Invoice::query()->where('contract_id', $response->json('data.id'))->firstOrFail();

        $contact->forceFill([
            'billing_name' => 'Changed Buyer',
            'billing_city' => 'Barcelona',
        ])->save();

        $entity->forceFill([
            'legal_name' => 'Changed Entity SL',
            'address_line1' => 'New Street 99',
        ])->save();

        $invoice->refresh();
        $this->assertSame('Original Buyer', $invoice->buyer_name);
        $this->assertSame('Madrid', $invoice->buyer_address['city'] ?? null);
        $this->assertSame($originalIssuerName, $invoice->issuer_name);
        $this->assertSame($originalIssuerTax, $invoice->issuer_tax_id);
    }

    public function test_charge_invoiced_once(): void
    {
        [$unit, $contact] = $this->seedSigningContext(amount: '70.00');

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '70.00',
            ]],
        ])->assertCreated();

        $contractId = $response->json('data.id');
        $chargeIds = Charge::query()->where('contract_id', $contractId)->pluck('id')->all();

        $this->postJson("/api/contracts/{$contractId}/invoices", [
            'charge_ids' => $chargeIds,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['charge_ids']);
    }

    public function test_no_mutation_routes(): void
    {
        $methods = ['PATCH', 'PUT', 'DELETE'];
        foreach ($methods as $method) {
            $this->assertFalse(
                Route::has("invoices.update") || collect(Route::getRoutes())->contains(
                    fn ($route) => in_array($method, $route->methods(), true)
                        && preg_match('#^api/invoices/\{[^}]+\}$#', $route->uri())
                ),
                "Unexpected {$method} route for invoices"
            );
        }

        $foundMutation = false;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/invoices')) {
                continue;
            }
            foreach (['PATCH', 'PUT', 'DELETE'] as $method) {
                if (in_array($method, $route->methods(), true)) {
                    $foundMutation = true;
                }
            }
        }

        $this->assertFalse($foundMutation, 'Invoice mutation routes must not exist');
    }

    public function test_entity_identity_frozen_after_first_invoice(): void
    {
        [$unit, $contact] = $this->seedSigningContext(amount: '60.00');

        $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '60.00',
            ]],
        ])->assertCreated();

        $entityId = $unit->site->legal_entity_id;

        $this->patchJson("/api/legal-entities/{$entityId}", [
            'tax_id' => 'B99999999',
            'country_code' => 'FR',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tax_id']);
    }

    /**
     * @return array{0: Unit, 1: Contact}
     */
    private function seedSigningContext(string $amount): array
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => $amount, 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();

        return [$unit, $contact];
    }
}
