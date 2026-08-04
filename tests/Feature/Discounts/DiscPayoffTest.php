<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Enums\BillingRunTrigger;
use App\Enums\ChargeType;
use App\Enums\StayPeriod;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingRunEngine;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DiscPayoffTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_free_period_boundary_bills_exact(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'Europe/Madrid'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        Setting::setBilling(Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
            billingAnchorModel: 'anniversary',
            defaultDepositAmount: '0.00',
            prorationMethod: 'daily',
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
        [$rate, $price] = $this->createUnitClassCataloguePrice(
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

        $discount = Discount::factory()->freeTime()->create();
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'expected_stay_length' => 3,
            'expected_stay_period' => StayPeriod::Month,
        ]);
        $offer = Offer::factory()->create(['contact_id' => $contact->id, 'deal_id' => $deal->id]);
        $option = OfferOption::query()->create([
            'offer_id' => $offer->id,
            'unit_class_rate_id' => $rate->id,
            'unit_id' => $unit->id,
            'discount_id' => $discount->id,
            'label' => 'Long-stay',
            'description' => null,
            'display_order' => 0,
            'selected_at' => null,
        ]);

        $create = $this->postJson('/api/reservations', [
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_id' => $unit->id,
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'offer_option_id' => $option->id,
            'expires_at' => '2026-08-20T23:30:00+02:00',
        ])->assertCreated();
        $reservationId = $create->json('data.id');

        $preview = $this->getJson("/api/reservations/{$reservationId}/convert-preview?".http_build_query([
            'move_in_date' => '2026-08-03',
            'unit_rate' => '184.90',
            'commitment_weeks' => 12,
        ]))->assertOk();

        $schedule = $preview->json('data.discount_schedule');
        $this->assertFalse($schedule['noop']);
        $this->assertSame([
            ['from' => '2026-08-03', 'to' => '2026-08-31', 'amount' => '0.00'],
            ['from' => '2026-08-31', 'to' => '2026-09-28', 'amount' => '92.45'],
            ['from' => '2026-09-28', 'to' => null, 'amount' => '184.90'],
        ], $schedule['segments']);
        $this->assertSame('0.00', $preview->json('data.first_period.unit.net'));

        $convert = $this->postJson("/api/reservations/{$reservationId}/convert", [
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'unit_rate' => '184.90',
            'commitment_weeks' => 12,
            'deposit_amount' => '0.00',
        ])->assertCreated();

        $contractId = $convert->json('data.id');
        $versions = ContractItem::query()
            ->where('contract_id', $contractId)
            ->where('item_type', 'unit')
            ->with('price')
            ->orderBy('effective_from')
            ->get();

        $this->assertCount(3, $versions);
        $this->assertSame('0.00', (string) $versions[0]->price->amount);
        $this->assertSame('92.45', (string) $versions[1]->price->amount);
        $this->assertSame('184.90', (string) $versions[2]->price->amount);
        $this->assertSame($discount->id, $versions[0]->discount_id);

        $first = Charge::query()
            ->where('contract_id', $contractId)
            ->where('charge_type', ChargeType::Rent)
            ->orderBy('period_start')
            ->firstOrFail();
        $this->assertSame('0.00', (string) $first->net_amount);
        $this->assertNull($first->invoice_id);

        // Bill subsequent periods across the free → partial → list boundary.
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Europe/Madrid'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Europe/Madrid'));

        (new BillingRunEngine)->run(BillingRunTrigger::Manual, contractId: $contractId);

        $rents = Charge::query()
            ->where('contract_id', $contractId)
            ->where('charge_type', ChargeType::Rent)
            ->orderBy('period_start')
            ->get()
            ->map(fn (Charge $c) => [
                'start' => $c->period_start?->toDateString(),
                'net' => (string) $c->net_amount,
            ])
            ->all();

        $this->assertContains(['start' => '2026-08-03', 'net' => '0.00'], $rents);
        $this->assertContains(['start' => '2026-08-31', 'net' => '92.45'], $rents);
        $this->assertContains(['start' => '2026-09-28', 'net' => '184.90'], $rents);

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
