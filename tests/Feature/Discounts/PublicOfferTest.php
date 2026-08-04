<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Enums\StayPeriod;
use App\Models\Contact;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class PublicOfferTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_promo_line_localized(): void
    {
        Setting::setBilling(Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
        ));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create();
        [$rate, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '184.90', 'currency' => 'EUR'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $discount = Discount::factory()->freeTime()->create();
        $contact = Contact::factory()->create(['locale' => 'es']);
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'expected_stay_length' => 2,
            'expected_stay_period' => StayPeriod::Month,
        ]);
        $offer = Offer::factory()->create([
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'token' => 'pub-token-disc-03',
            'status' => 'sent',
        ]);
        OfferOption::query()->create([
            'offer_id' => $offer->id,
            'unit_class_rate_id' => $rate->id,
            'unit_id' => $unit->id,
            'discount_id' => $discount->id,
            'label' => 'Long-stay unit',
            'description' => null,
            'display_order' => 0,
            'selected_at' => null,
        ]);

        $response = $this->getJson('/api/offers/token/pub-token-disc-03')
            ->assertOk();

        $option = $response->json('data.options.0');
        $this->assertNotNull($option['discount']);
        $this->assertSame($discount->id, $option['discount']['id']);
        $this->assertSame('free_time', $option['discount']['kind']);
        $this->assertSame('Primeras 4 semanas gratis', $option['promo_line']);
        $this->assertNull($option['discount_resolution']['warning']);
        $this->assertFalse($option['discount_resolution']['noop']);
        $this->assertSame(4, $option['discount_resolution']['resolved_tier']['free_weeks']);
        $this->assertSame(8, $option['discount_resolution']['commitment_weeks']);
    }
}
