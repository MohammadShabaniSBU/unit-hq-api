<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Enums\ContactChannelType;
use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Note;
use App\Models\Offer;
use App\Models\Price;
use App\Models\Reservation;
use App\Models\UnitClassRate;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SizeGuide;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\GroundingGuard;
use App\Support\Ai\Tools\AgentWriteAttribution;
use App\Support\Billing\BillingMath;
use App\Support\Facility\SizeGuideResolver;
use App\Support\Time\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class SalesAndPublicToolTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function availability_returns_counts_not_unit_identifiers(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Norte']);
        $class = UnitClass::factory()->create(['label' => 'Small', 'size' => 6]);
        Unit::factory()->count(3)->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'enabled' => true,
        ]);

        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $result = $this->dispatchTool(
            'sales',
            'facility.availability',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(3, $result->data['classes'][0]['count']);
        $this->assertSame('Small', $result->data['classes'][0]['label']);
        $this->assertSame('Madrid Norte', $result->data['classes'][0]['site_name']);
        $this->assertStringContainsString('3 units available in Small', $result->display);
        $this->assertStringContainsString('as of now', $result->display);
        $this->assertStringNotContainsString(Unit::query()->first()->unit_number, $result->display);
        $this->assertSame(SiteClock::today($site)->toDateString(), $result->data['as_of']);
        $this->assertNotEmpty($result->entities);
        $types = array_map(fn ($ref) => $ref->type->value, $result->entities);
        $this->assertContains('site', $types);
        $this->assertContains('unit_class', $types);
        $this->assertNotContains('unit', $types);
        $this->assertArrayNotHasKey('entities', $result->data);
    }

    #[Test]
    public function quote_matches_billing_math_to_the_cent(): void
    {
        [$site, $class] = $this->pricedClass('70.00', '21.00');
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $result = $this->dispatchTool(
            'sales',
            'pricing.quote',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $expected = BillingMath::applyTax('70.00', '21.00');
        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame($expected->net, $result->data['net']);
        $this->assertSame($expected->tax, $result->data['tax']);
        $this->assertSame($expected->gross, $result->data['gross']);
        $this->assertSame('EUR', $result->data['currency']);
        $this->assertSame('Small', $result->data['label']);
        $this->assertSame('Small', $result->data['unit_class_label']);
        $this->assertSame($site->name, $result->data['site_name']);
        $this->assertNotNull($result->data['price_id']);
        $this->assertNotNull($result->data['tax_rate_id']);
        $this->assertSame('month', $result->data['billing_interval']);
        $this->assertSame(1, $result->data['billing_interval_count']);
        $this->assertSame(SiteClock::today($site)->toDateString(), $result->data['as_of']);
        $this->assertStringContainsString('€70.00 net', $result->display);
        $this->assertStringContainsString('per month', $result->display);
        $this->assertStringContainsString('Small', $result->display);
        $this->assertStringContainsString($site->name, $result->display);
        $this->assertTrue($result->facts->contains('70.00'));
        $this->assertNotEmpty($result->entities);
        $quoteTypes = array_map(fn ($ref) => $ref->type->value, $result->entities);
        $this->assertContains('unit_class', $quoteTypes);
        $this->assertContains('site', $quoteTypes);
    }

    #[Test]
    public function quote_currency_comes_from_the_price_row(): void
    {
        [$site, $class] = $this->pricedClass('70.00', '21.00');
        $site->update(['currency' => 'USD']);
        Setting::setBilling(Setting::billing()->with(defaultCurrency: 'GBP'));
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $result = $this->dispatchTool(
            'sales',
            'pricing.quote',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('EUR', $result->data['currency']);
        $this->assertStringContainsString('€', $result->display);
    }

    #[Test]
    public function quote_unresolvable_tax_errors_with_handoff(): void
    {
        $employee = Employee::factory()->create();
        Country::factory()->create(['code' => 'ES']);
        $fr = Country::factory()->create(['code' => 'FR']);
        $site = Site::factory()->create(['country_id' => $fr->id]);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat']);
        $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, ['amount' => '70.00']);
        TaxRate::query()->create([
            'name' => 'VAT ES',
            'code' => 'vat',
            'rate' => '21.00',
            'jurisdiction' => 'ES',
            'is_default' => false,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $employee->id,
        ]);

        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $result = $this->dispatchTool(
            'sales',
            'pricing.quote',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(HandoffReason::Error, $result->handoffReason);
    }

    #[Test]
    public function discounts_return_id_label_display_only(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Centro']);
        $terms = 'Commit to 4 weeks or more and your first 2 weeks are free.';
        $discount = Discount::factory()->percent('10.00')->agentOfferable([
            'en' => $terms,
        ])->create(['name' => 'Spring 10']);
        Discount::factory()->percent('20.00')->create(['name' => 'Walk-in 20']);
        Discount::factory()->percent('30.00')->archived()->agentOfferable([
            'en' => '30% off archived.',
        ])->create();

        $result = $this->dispatchTool(
            'sales',
            'pricing.discounts',
            AgentPrincipal::anonymous($site->id, 'en'),
            ['site_id' => $site->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertCount(1, $result->data['discounts']);
        $this->assertSame($discount->id, $result->data['discounts'][0]['id']);
        $this->assertSame('Spring 10', $result->data['discounts'][0]['label']);
        $this->assertSame($terms, $result->data['discounts'][0]['display']);
        $this->assertArrayNotHasKey('params', $result->data['discounts'][0]);
        $this->assertStringNotContainsString('Walk-in 20', $result->display);
        $this->assertStringContainsString($terms, $result->display);
        $this->assertCount(1, $result->entities);
        $this->assertSame($discount->id, $result->entities[0]->id);
    }

    #[Test]
    public function discounts_locale_ladder_picks_principal_then_english(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Centro']);
        Discount::factory()->percent('10.00')->agentOfferable([
            'en' => 'Commit to 4 weeks or more and your first 2 weeks are free.',
            'es' => 'Comprométete a 4 semanas o más y las 2 primeras semanas son gratis.',
        ])->create(['name' => 'Long-stay promo']);

        $spanish = $this->dispatchTool(
            'sales',
            'pricing.discounts',
            AgentPrincipal::anonymous($site->id, 'es'),
            ['site_id' => $site->id],
        );
        $this->assertSame(
            'Comprométete a 4 semanas o más y las 2 primeras semanas son gratis.',
            $spanish->data['discounts'][0]['display'],
        );

        $frenchFallsBack = $this->dispatchTool(
            'sales',
            'pricing.discounts',
            AgentPrincipal::anonymous($site->id, 'fr'),
            ['site_id' => $site->id],
        );
        $this->assertSame(
            'Commit to 4 weeks or more and your first 2 weeks are free.',
            $frenchFallsBack->data['discounts'][0]['display'],
        );
    }

    #[Test]
    public function discounts_empty_catalogue_names_the_site_and_licenses_nothing(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Centro']);
        Discount::factory()->percent('20.00')->create(['name' => 'Walk-in 20']);

        $result = $this->dispatchTool(
            'sales',
            'pricing.discounts',
            AgentPrincipal::anonymous($site->id, 'en'),
            ['site_id' => $site->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame([], $result->data['discounts']);
        $this->assertSame([], $result->entities);
        $this->assertSame('No promotions are currently available at Madrid Centro.', $result->display);
        $this->assertStringNotContainsString('Refs:', $result->modelText());
    }

    #[Test]
    public function propose_offer_writes_no_rows(): void
    {
        [$site, $class] = $this->pricedClass('70.00', '21.00');
        $before = [
            'offers' => Offer::query()->count(),
            'reservations' => Reservation::query()->count(),
            'contracts' => Contract::query()->count(),
        ];
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $result = $this->dispatchTool(
            'sales',
            'sales.propose_offer',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertFalse($result->data['persisted']);
        $this->assertSame($before['offers'], Offer::query()->count());
        $this->assertSame($before['reservations'], Reservation::query()->count());
        $this->assertSame($before['contracts'], Contract::query()->count());
        $this->assertStringContainsString('Nothing has been sent or saved', $result->display);
        $this->assertArrayHasKey('price_id', $result->data['line_items'][0]);
        $this->assertArrayHasKey('tax_rate_id', $result->data['line_items'][0]);
        $this->assertSame($site->id, $result->data['line_items'][0]['site_id']);
        $this->assertSame($class->id, $result->data['line_items'][0]['unit_class_id']);
    }

    #[Test]
    public function propose_offer_echoes_expected_move_in(): void
    {
        [$site, $class] = $this->pricedClass('70.00', '21.00');
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $result = $this->dispatchTool(
            'sales',
            'sales.propose_offer',
            $principal,
            [
                'site_id' => $site->id,
                'unit_class_id' => $class->id,
                'expected_move_in' => '2026-08-31',
            ],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('2026-08-31', $result->data['expected_move_in']);
        $this->assertStringContainsString('Proposed move-in 2026-08-31.', $result->display);
        $this->assertTrue($result->facts->contains('2026-08-31'));
        $this->assertTrue($result->facts->contains('31/08/2026'));
    }

    #[Test]
    public function propose_offer_after_a_quote_reuses_the_quoted_price(): void
    {
        [$site, $class] = $this->pricedClass('70.00', '21.00');
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $quote = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $quote->status);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
        ], $quote, $principal);

        $result = $this->dispatchTool(
            'sales',
            'sales.propose_offer',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame($quote->data['price_id'], $result->data['line_items'][0]['price_id']);
        $this->assertSame($quote->data['tax_rate_id'], $result->data['line_items'][0]['tax_rate_id']);
        $this->assertSame($site->id, $result->data['line_items'][0]['site_id']);
        $this->assertSame($class->id, $result->data['line_items'][0]['unit_class_id']);
    }

    #[Test]
    public function propose_offer_after_a_quote_refuses_when_the_catalogue_moved(): void
    {
        [$site, $class] = $this->pricedClass('70.00', '21.00');
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $class);

        $quote = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
        ], $quote, $principal);

        $rate = UnitClassRate::query()
            ->where('site_id', $site->id)
            ->where('unit_class_id', $class->id)
            ->with('price')
            ->firstOrFail();
        $old = $rate->price;
        $this->assertNotNull($old);
        $old->update(['effective_to' => now()->toDateString()]);
        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $rate->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => '120.00',
            'currency' => 'EUR',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $old->created_by,
        ]);
        $rate->unsetRelation('price');

        $result = $this->dispatchTool(
            'sales',
            'sales.propose_offer',
            $principal,
            ['site_id' => $site->id, 'unit_class_id' => $class->id],
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::PriceSuperseded, $result->error?->errorCode);
        $this->assertSame('price', $result->error?->detail['superseded'] ?? null);
        $this->assertSame($quote->data['price_id'], $result->error?->detail['quoted'] ?? null);
    }

    #[Test]
    public function create_deal_writes_need_fields_and_licenses_the_move_in_date(): void
    {
        $contact = Contact::factory()->create(['source' => ContactSource::AiAgent]);
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $contact);

        $result = $this->dispatchTool('sales', 'crm.create_deal', $principal, [
            'contact_id' => $contact->id,
            'expected_move_in' => '2026-08-31',
            'expected_stay_length' => 6,
            'expected_stay_period' => 'month',
            'desired_size_m2' => '12.5',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $deal = Deal::query()->findOrFail($result->data['deal_id']);
        $this->assertSame('2026-08-31', $deal->expected_move_in?->toDateString());
        $this->assertEquals(6, $deal->expected_stay_length);
        $this->assertSame('month', $deal->expected_stay_period?->value);
        $this->assertSame('12.50', $deal->desired_size);
        $this->assertStringContainsString('Expected move-in 2026-08-31.', $result->display);
        $this->assertStringContainsString('Expected stay 6 month.', $result->display);
        $this->assertStringContainsString('Desired size 12.50 m².', $result->display);

        $pass = app(GroundingGuard::class)->check(
            'You can move in on 31/08/2026.',
            $result->facts,
            $ctx,
        );
        $this->assertTrue($pass->passed);
    }

    #[Test]
    public function create_deal_rejects_stay_length_without_period(): void
    {
        $contact = Contact::factory()->create(['source' => ContactSource::AiAgent]);
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $contact);

        $result = $this->dispatchTool('sales', 'crm.create_deal', $principal, [
            'contact_id' => $contact->id,
            'expected_stay_length' => 6,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertSame(0, Deal::query()->count());
    }

    #[Test]
    public function create_deal_warns_when_notes_are_dropped_without_operator_attribution(): void
    {
        $contact = Contact::factory()->create(['source' => ContactSource::AiAgent]);
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales', origin: AgentOrigin::Webchat);
        $ctx->conversation->forceFill(['created_by_employee_id' => null])->save();
        $this->licenseModels($ctx, $contact);

        $result = $this->dispatchTool('sales', 'crm.create_deal', $principal, [
            'contact_id' => $contact->id,
            'notes' => 'Business storage, 20 boxes.',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertStringContainsString(AgentWriteAttribution::NOTES_NOT_WRITTEN, $result->display);
        $this->assertSame(0, Note::query()->count());
    }

    #[Test]
    public function create_contact_warns_when_notes_are_dropped_without_operator_attribution(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales', origin: AgentOrigin::Webchat);
        $ctx->conversation->forceFill(['created_by_employee_id' => null])->save();

        $result = $this->dispatchTool('sales', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-notes@example.com',
            'notes' => 'Called from webchat.',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertStringContainsString(AgentWriteAttribution::NOTES_NOT_WRITTEN, $result->display);
        $this->assertSame(0, Note::query()->count());
    }

    #[Test]
    public function create_contact_deduplicates_on_existing_channel(): void
    {
        $existing = Contact::factory()->create(['email' => 'ada@example.com']);
        $existing->channels()->create([
            'type' => ContactChannelType::Email,
            'value' => 'ada@example.com',
            'is_primary' => true,
        ]);

        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $result = $this->dispatchTool('sales', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertTrue($result->data['matched']);
        $this->assertSame($existing->id, $result->data['contact_id']);
        $this->assertSame(1, Contact::query()->count());
    }

    #[Test]
    public function create_contact_sets_ai_agent_source(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $result = $this->dispatchTool('sales', 'crm.create_contact', $principal, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'new@example.com',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertFalse($result->data['matched']);
        $contact = Contact::query()->findOrFail($result->data['contact_id']);
        $this->assertSame(ContactSource::AiAgent, $contact->source);
    }

    #[Test]
    public function create_deal_rejects_non_agent_contact_when_anonymous(): void
    {
        $tenant = Contact::factory()->create(['source' => null]);
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $this->licenseModels($ctx, $tenant);

        $result = $this->dispatchTool('sales', 'crm.create_deal', $principal, [
            'contact_id' => $tenant->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Ownership, $result->deniedReason);
        $this->assertSame(0, Deal::query()->count());
    }

    #[Test]
    public function size_guide_licenses_capacity_and_cites_the_conservative_band(): void
    {
        SizeGuide::factory()->create([
            'metric' => 'standard_boxes',
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
        ]);

        $result = $this->dispatchTool(
            'sales',
            'facility.size_guide',
            AgentPrincipal::anonymous(null, 'en'),
            ['metric' => 'standard_boxes', 'quantity' => 24],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame([ForbiddenClaimKey::CapacityGuidance], $result->licensedClaims);
        $this->assertStringContainsString('12–16', $result->display);
        $this->assertStringContainsString(SizeGuideResolver::DISCLAIMER, $result->display);
        $this->assertNotEmpty($result->entities);
    }

    #[Test]
    public function size_guide_empty_match_is_not_found_and_does_not_license(): void
    {
        $result = $this->dispatchTool(
            'sales',
            'facility.size_guide',
            AgentPrincipal::anonymous(null, 'en'),
            ['metric' => 'standard_boxes', 'quantity' => 24],
        );

        $this->assertSame(ToolInvocationStatus::NotFound, $result->status);
        $this->assertSame([], $result->licensedClaims);
    }

    #[Test]
    public function faq_unknown_key_returns_not_found(): void
    {
        $result = $this->dispatchTool(
            'sales',
            'kb.faq_lookup',
            AgentPrincipal::anonymous(null, 'en'),
            ['key' => 'made_up_policy'],
        );

        $this->assertSame(ToolInvocationStatus::NotFound, $result->status);
    }

    #[Test]
    public function faq_returns_curated_snippet(): void
    {
        $result = $this->dispatchTool(
            'sales',
            'kb.faq_lookup',
            AgentPrincipal::anonymous(null, 'en'),
            ['key' => 'access_hours'],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertStringContainsString('06:00', $result->display);
    }

    #[Test]
    public function site_info_includes_hours_from_knowledge(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Norte', 'timezone' => 'Europe/Madrid']);

        $result = $this->dispatchTool(
            'sales',
            'facility.site_info',
            AgentPrincipal::anonymous($site->id, 'en'),
            ['site_id' => $site->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('Europe/Madrid', $result->data['timezone']);
        $this->assertNotNull($result->data['access_hours']);
    }

    #[Test]
    public function site_info_without_site_and_no_active_sites_is_unresolved(): void
    {
        $result = $this->dispatchTool(
            'sales',
            'facility.site_info',
            AgentPrincipal::anonymous(null, 'en'),
            [],
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::SiteUnresolved, $result->error?->errorCode);
        $this->assertSame('facility.find_sites', $result->error?->recovery['tool'] ?? null);
        $this->assertNull($result->handoffReason);
        $this->assertStringContainsString('facility.find_sites', $result->display);
        $this->assertSame([], $result->error?->candidates);
    }

    #[Test]
    public function site_info_without_args_returns_the_only_active_site(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Centro', 'timezone' => 'Europe/Madrid']);

        $result = $this->dispatchTool(
            'sales',
            'facility.site_info',
            AgentPrincipal::anonymous(null, 'en'),
            [],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame($site->id, $result->data['site_id']);
        $this->assertSame('only_site', $result->data['match_reason']);
        $this->assertStringContainsString('match_reason: only_site', $result->display);
        $this->assertCount(1, $result->entities);
    }

    #[Test]
    public function site_info_without_args_and_three_sites_returns_candidates(): void
    {
        Site::factory()->count(3)->create();

        $result = $this->dispatchTool(
            'sales',
            'facility.site_info',
            AgentPrincipal::anonymous(null, 'en'),
            [],
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::SiteUnresolved, $result->error?->errorCode);
        $this->assertCount(3, $result->error?->candidates ?? []);
        $this->assertSame('facility.find_sites', $result->error?->recovery['tool'] ?? null);
    }

    #[Test]
    public function site_info_with_principal_site_and_empty_args_succeeds_when_other_sites_exist(): void
    {
        $site = Site::factory()->create(['name' => 'Seeded site', 'timezone' => 'Europe/Madrid']);
        Site::factory()->count(2)->create();

        $result = $this->dispatchTool(
            'sales',
            'facility.site_info',
            AgentPrincipal::anonymous($site->id, 'en'),
            [],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame($site->id, $result->data['site_id']);
        $this->assertArrayNotHasKey('match_reason', $result->data);
    }

    #[Test]
    public function find_sites_emits_entities_and_puts_match_reason_in_display(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Centro', 'city' => 'Madrid', 'postal_code' => '28004']);

        $result = $this->dispatchTool(
            'sales',
            'facility.find_sites',
            AgentPrincipal::anonymous(null, 'en'),
            ['query' => 'Barcelona'],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('no_match', $result->data['sites'][0]['match_reason']);
        $this->assertSame($site->id, $result->data['sites'][0]['site_id']);
        $this->assertStringContainsString('match_reason: no_match', $result->display);
        $this->assertCount(1, $result->entities);
        $this->assertSame($site->id, $result->entities[0]->id);
    }

    /**
     * @return array{0: Site, 1: UnitClass}
     */
    private function pricedClass(string $amount, string $rate): array
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Small']);
        $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => $amount,
            'currency' => 'EUR',
        ]);
        TaxRate::query()->create([
            'name' => 'VAT ES',
            'code' => 'vat',
            'rate' => $rate,
            'jurisdiction' => 'ES',
            'is_default' => false,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $employee->id,
        ]);

        return [$site, $class];
    }
}
