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
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Billing\BillingMath;
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
        $this->assertSame($site->name, $result->data['site_name']);
        $this->assertStringContainsString('€70.00', $result->display);
        $this->assertTrue($result->facts->contains('70.00'));
        $this->assertNotEmpty($result->entities);
        $quoteTypes = array_map(fn ($ref) => $ref->type->value, $result->entities);
        $this->assertContains('unit_class', $quoteTypes);
        $this->assertContains('site', $quoteTypes);
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
        $site = Site::factory()->create();
        $discount = Discount::factory()->percent('10.00')->create(['name' => 'Spring 10']);
        Discount::factory()->percent('20.00')->archived()->create();

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
        $this->assertArrayHasKey('display', $result->data['discounts'][0]);
        $this->assertArrayNotHasKey('params', $result->data['discounts'][0]);
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
