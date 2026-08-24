<?php

declare(strict_types=1);

namespace Tests\Feature\Leasing;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Enums\DealStatus;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class OfferCreationTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Unit $unit;

    private int $unitClassRateId;

    private Contact $contact;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = $this->authenticateAsEmployee();

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [$rate] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
        );
        $this->unitClassRateId = $rate->id;
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'enabled' => true,
        ]);
        $this->contact = Contact::factory()->create();
        $this->deal = Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $this->site->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $this->unitClass->id,
        ]);
    }

    #[Test]
    public function token_is_sixty_four_chars_and_not_the_pk(): void
    {
        $response = $this->postJson('/api/offers', [
            'deal_id' => $this->deal->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertCreated();

        $offer = Offer::query()->findOrFail($response->json('data.id'));
        $this->assertSame(64, strlen((string) $offer->token));
        $this->assertNotSame((string) $offer->id, $offer->token);
    }

    #[Test]
    public function each_option_carries_a_pinned_unit_id(): void
    {
        $response = $this->postJson('/api/offers', [
            'deal_id' => $this->deal->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(7)->toDateString(),
            'options' => [
                [
                    'unit_class_rate_id' => $this->unitClassRateId,
                    'label' => 'Standard',
                    'display_order' => 0,
                ],
            ],
        ]);

        $response->assertCreated();

        $option = OfferOption::query()
            ->where('offer_id', $response->json('data.id'))
            ->firstOrFail();
        $this->assertSame($this->unit->id, $option->unit_id);
    }

    #[Test]
    public function custom_attributes_are_applied(): void
    {
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Offer,
            'key' => 'promo_code',
            'label' => 'Promo code',
            'type' => AttributeType::Text,
        ]);

        $response = $this->postJson('/api/offers', [
            'deal_id' => $this->deal->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(7)->toDateString(),
            'attributes' => [
                [
                    'definition_id' => $definition->id,
                    'value' => 'SUMMER',
                ],
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('attribute_values', [
            'definition_id' => $definition->id,
            'entity_id' => $response->json('data.id'),
            'value_text' => 'SUMMER',
        ]);
    }
}
