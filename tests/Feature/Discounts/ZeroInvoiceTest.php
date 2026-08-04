<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ZeroInvoiceTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_skip_flag_cursor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'Europe/Madrid'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        \App\Models\Setting::setBilling(\App\Models\Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
            defaultDepositAmount: '0.00',
        ));

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '184.90', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();
        $discount = Discount::factory()->freeTime()->create();

        config(['fiscal.invoice_zero_periods' => false]);

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'commitment_weeks' => 8,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '184.90',
            ]],
        ])->assertCreated();

        $contractId = $response->json('data.id');
        $charge = Charge::query()
            ->where('contract_id', $contractId)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $this->assertSame('0.00', (string) $charge->net_amount);
        $this->assertNull($charge->invoice_id);
        $this->assertSame(0, Invoice::query()->where('contract_id', $contractId)->count());

        $contract = \App\Models\Contract::query()->findOrFail($contractId);
        $this->assertNotNull($contract->billed_through);

        // Flag on → zero-total invoice is issued.
        config(['fiscal.invoice_zero_periods' => true]);

        $unit2 = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact2 = Contact::factory()->create();

        $withFlag = $this->postJson('/api/contracts', [
            'contact_id' => $contact2->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'commitment_weeks' => 8,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit2->id,
                'amount' => '184.90',
            ]],
        ])->assertCreated();

        $contract2Id = $withFlag->json('data.id');
        $charge2 = Charge::query()
            ->where('contract_id', $contract2Id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();
        $this->assertSame('0.00', (string) $charge2->net_amount);
        $this->assertNotNull($charge2->invoice_id);
        $this->assertSame(1, Invoice::query()->where('contract_id', $contract2Id)->count());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
