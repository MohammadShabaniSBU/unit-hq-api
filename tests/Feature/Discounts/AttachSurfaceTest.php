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
use App\Models\Setting;
use App\Models\Site;
use App\Support\Discounts\DiscountSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_moments_tier_resolution_warnings(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        Setting::setBilling(Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
        ));

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
        ]);

        $discount = Discount::factory()->freeTime()->create();
        $contact = Contact::factory()->create();

        $dealWithStay = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'expected_stay_length' => 2,
            'expected_stay_period' => StayPeriod::Month,
        ]);

        $dealNoStay = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'expected_stay_length' => null,
            'expected_stay_period' => null,
        ]);

        // Offer-option moment: deal stay 2 months → 8 weeks → first 4 weeks free.
        $withStay = $this->getJson('/api/discounts/'.$discount->id.'/resolve?'.http_build_query([
            'deal_id' => $dealWithStay->id,
            'list_amount' => '184.90',
            'currency' => 'EUR',
            'locale' => 'en',
        ]))->assertOk()->json('data');

        $this->assertSame(8, $withStay['commitment_weeks']);
        $this->assertNull($withStay['warning']);
        $this->assertFalse($withStay['noop']);
        $this->assertSame(4, $withStay['resolved_tier']['free_weeks']);
        $this->assertSame('First 4 weeks free', $withStay['promo_line']);
        $this->assertNotNull($withStay['discount_schedule']);
        $this->assertSame('0.00', $withStay['discount_schedule']['segments'][0]['amount']);

        // Honest warning — never silent best tier when stay is missing.
        $noStay = $this->getJson('/api/discounts/'.$discount->id.'/resolve?'.http_build_query([
            'deal_id' => $dealNoStay->id,
            'list_amount' => '184.90',
            'currency' => 'EUR',
        ]))->assertOk()->json('data');

        $this->assertNull($noStay['commitment_weeks']);
        $this->assertSame(DiscountSurface::WARNING_NO_STAY_LENGTH, $noStay['warning']);
        $this->assertTrue($noStay['noop']);
        $this->assertNull($noStay['resolved_tier']);
        $this->assertNull($noStay['promo_line']);

        // Walk-in moment: explicit commitment_weeks resolves the same tier.
        $walkIn = $this->getJson('/api/discounts/'.$discount->id.'/resolve?'.http_build_query([
            'commitment_weeks' => 8,
            'list_amount' => '184.90',
            'currency' => 'EUR',
            'locale' => 'es',
        ]))->assertOk()->json('data');

        $this->assertSame(8, $walkIn['commitment_weeks']);
        $this->assertNull($walkIn['warning']);
        $this->assertFalse($walkIn['noop']);
        $this->assertSame(4, $walkIn['resolved_tier']['free_weeks']);
        $this->assertSame('Primeras 4 semanas gratis', $walkIn['promo_line']);
    }
}
