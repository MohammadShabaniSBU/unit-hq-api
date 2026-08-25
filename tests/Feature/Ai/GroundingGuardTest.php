<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\GroundingGuard;
use App\Support\Ai\Tools\FactBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class GroundingGuardTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function invented_amount_is_blocked(): void
    {
        $verdict = app(GroundingGuard::class)->check(
            'You owe €12.00.',
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('grounding', $verdict->blockedBy);
        $this->assertSame('€12.00', $verdict->detail['token'] ?? null);
    }

    #[Test]
    public function tool_sourced_amount_passes(): void
    {
        $facts = (new FactBag)->money('84.70', 'EUR');
        $verdict = app(GroundingGuard::class)->check(
            'The figure is €84.70.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function customer_echoed_amount_passes(): void
    {
        $facts = FactBag::fromCustomerMessage('You quoted €80.00 yesterday.');
        $verdict = app(GroundingGuard::class)->check(
            'You said €80.00.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function invented_tax_percent_is_blocked(): void
    {
        $verdict = app(GroundingGuard::class)->check(
            'That includes 21% tax.',
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('grounding', $verdict->blockedBy);
        $this->assertStringContainsString('21%', (string) ($verdict->detail['token'] ?? ''));
    }

    #[Test]
    public function comma_amount_matches_dot_amount(): void
    {
        $facts = (new FactBag)->money('84.70', 'EUR');
        $verdict = app(GroundingGuard::class)->check(
            'The price is €84,70.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function tool_sourced_percent_passes(): void
    {
        $facts = (new FactBag)->percent('21');
        $verdict = app(GroundingGuard::class)->check(
            'VAT is 21%.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function licensed_offer_amount_passes_and_unlicensed_is_suppressed(): void
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Small']);
        [$rate] = $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => '70.00',
            'currency' => 'EUR',
        ]);
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
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'enabled' => true,
        ]);
        $contact = Contact::factory()->create(['source' => ContactSource::AiAgent]);
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $class->id,
        ]);

        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $deal->id,
            'options' => [[
                'unit_class_rate_id' => $rate->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);

        $pass = app(GroundingGuard::class)->check('The offer is €84.70.', $result->facts, $ctx);
        $this->assertTrue($pass->passed);

        $fail = app(GroundingGuard::class)->check('The offer is €12.00.', $result->facts, $ctx);
        $this->assertFalse($fail->passed);
        $this->assertSame('grounding', $fail->blockedBy);
        $this->assertSame('€12.00', $fail->detail['token'] ?? null);
    }

    #[Test]
    public function licensed_hold_expiry_passes_and_unlicensed_date_is_suppressed(): void
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $class = UnitClass::factory()->create([
            'tax_rate_code' => 'vat',
            'label' => '10 m²',
        ]);
        $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => '70.00',
            'currency' => 'EUR',
        ]);
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'enabled' => true,
        ]);
        $contact = Contact::factory()->create(['source' => ContactSource::AiAgent]);
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $class->id,
        ]);

        $principal = AgentPrincipal::channelAsserted($contact->id, $site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $deal->id,
            'unit_class_id' => $class->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $expiresOn = (string) ($result->data['expires_on'] ?? '');
        $this->assertNotSame('', $expiresOn);

        $pass = app(GroundingGuard::class)->check(
            "Hold until {$expiresOn}.",
            $result->facts,
            $ctx,
        );
        $this->assertTrue($pass->passed);

        $fail = app(GroundingGuard::class)->check(
            'Hold until 2099-01-01.',
            $result->facts,
            $ctx,
        );
        $this->assertFalse($fail->passed);
        $this->assertSame('grounding', $fail->blockedBy);
    }

    #[Test]
    public function licensed_offer_url_passes_grounding(): void
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Small']);
        [$rate] = $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => '70.00',
            'currency' => 'EUR',
        ]);
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
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'enabled' => true,
        ]);
        $contact = Contact::factory()->create(['source' => ContactSource::AiAgent]);
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $class->id,
        ]);

        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $deal->id,
            'options' => [[
                'unit_class_rate_id' => $rate->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $url = (string) ($result->data['url'] ?? '');
        $this->assertNotSame('', $url);

        $pass = app(GroundingGuard::class)->check("Here is your offer: {$url}", $result->facts, $ctx);
        $this->assertTrue($pass->passed);
    }
}
