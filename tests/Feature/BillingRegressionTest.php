<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ContractBilling;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * First-period charges must stay byte-identical through the itemsOn / price
 * refactor (signing periodStart = move_in).
 */
class BillingRegressionTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_first_period_charges_unchanged(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '196.72', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();

        $moveIn = '2026-07-10';
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '196.72',
            ]],
        ]);

        $response->assertCreated();

        $charges = Charge::query()
            ->where('contract_id', $response->json('data.id'))
            ->orderBy('id')
            ->get();

        $this->assertNotEmpty($charges);

        $plan = ContractBilling::planFirstPeriod(
            CarbonImmutable::parse($moveIn),
            $response->json('data.billing_anchor_model'),
            $response->json('data.billing_interval'),
            (int) $response->json('data.billing_interval_count'),
            1,
        );

        $expectedNet = ContractBilling::firstPeriodNetForItem(
            $plan,
            '196.72',
            $response->json('data.proration_method'),
        );
        $expected = BillingMath::applyTax($expectedNet, null);

        $rent = $charges->firstWhere('charge_type', 'rent') ?? $charges->first();
        $this->assertSame($expected->net, (string) $rent->net_amount);
        $this->assertSame($expected->tax, (string) $rent->tax_amount);
        $this->assertSame($expected->gross, (string) $rent->amount);
        $this->assertSame($plan->windowStart->toDateString(), $rent->period_start?->toDateString());
        $this->assertSame($plan->windowEnd->toDateString(), $rent->period_end?->toDateString());
    }
}
