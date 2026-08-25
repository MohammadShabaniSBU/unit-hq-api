<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\PipelineSource;
use App\Models\AgentWritePolicy;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OfferDelivery;
use App\Models\OfferOption;
use App\Models\Price;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\CanonicalJson;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\FactRegistry;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\SalesCreateOfferTool;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Leasing\OfferCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class SalesCreateOfferToolTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function happy_path_creates_one_offer_with_pinned_options(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(1, Offer::query()->count());
        $offer = Offer::query()->firstOrFail();
        $this->assertSame(64, strlen((string) $offer->token));
        $this->assertNotSame((string) $offer->id, $offer->token);
        $this->assertSame('draft', $offer->status);
        $this->assertSame(PipelineSource::AiAgent, $offer->source);
        $this->assertSame($ctx->agent->id, $offer->ai_agent_id);
        $this->assertSame($world['deal']->contact_id, $offer->contact_id);
        $this->assertTrue($offer->expires_at->isSameDay(OfferCreation::defaultExpiry()));

        $option = OfferOption::query()->with('unit')->firstOrFail();
        $this->assertSame($world['rate']->id, $option->unit_class_rate_id);
        $this->assertNotNull($option->unit_id);
        $this->assertSame($option->unit_id, $result->data['options'][0]['unit_id'] ?? null);
        $this->assertStringNotContainsString((string) $option->unit->unit_number, $result->display);
        $this->assertStringContainsString('/preview/offer/'.$offer->token, $result->display);
        $this->assertStringContainsString('Nothing has been sent', $result->display);
    }

    #[Test]
    public function attempts_expiry_override_is_ignored(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'expires_at' => now()->addDays(400)->toIso8601String(),
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $offer = Offer::query()->firstOrFail();
        $this->assertTrue($offer->expires_at->isSameDay(OfferCreation::defaultExpiry()));
        $this->assertTrue($offer->expires_at->lt(now()->addDays(30)));
    }

    #[Test]
    public function rejects_freeform_discount(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
                'percent' => 90,
                'amount' => '12.00',
                'discount_value' => '50%',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $option = OfferOption::query()->firstOrFail();
        $this->assertNull($option->discount_id);
    }

    #[Test]
    public function rejects_cross_site_rate(): void
    {
        $world = $this->agentPricedDeal();
        $otherCountry = Country::factory()->create(['code' => 'GB']);
        $otherSite = Site::factory()->create(['country_id' => $otherCountry->id, 'currency' => 'GBP']);
        [$foreignRate] = $this->createUnitClassCataloguePrice(
            $world['class']->id,
            $otherSite->id,
            Employee::factory()->create()->id,
            ['amount' => '80.00', 'currency' => 'GBP'],
        );

        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $foreignRate->id,
                'label' => 'Foreign',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function anonymous_ownership(): void
    {
        $world = $this->agentPricedDeal();
        $tenant = Contact::factory()->create(['source' => null]);
        $foreignDeal = Deal::factory()->create([
            'contact_id' => $tenant->id,
            'site_id' => $world['site']->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $world['class']->id,
        ]);

        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class'], $foreignDeal);

        $denied = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $foreignDeal->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Denied, $denied->status);
        $this->assertSame(ToolDeniedReason::Ownership, $denied->deniedReason);
        $this->assertSame(0, Offer::query()->count());

        $ok = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $ok->status);
        $this->assertSame(1, Offer::query()->count());
    }

    #[Test]
    public function no_send(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);
        $messages = Message::query()->count();
        $deliveries = OfferDelivery::query()->count();

        $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame($messages, Message::query()->count());
        $this->assertSame($deliveries, OfferDelivery::query()->count());

        $source = (string) file_get_contents(app_path('Support/Ai/Tools/SalesCreateOfferTool.php'));
        foreach (['SendContext', 'EmailSender', 'SmsSender', 'WhatsAppSender', 'OfferDelivery', 'Message'] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }

    #[Test]
    public function propose_mode_writes_nothing(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);
        AgentWritePolicy::factory()->propose()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'sales.create_offer',
        ]);
        $ctx->agent->load('writePolicies');

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::RequiresApproval, $result->deniedReason);
        $this->assertSame(CannedReply::pendingApproval('en'), $result->display);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function propose_payload_is_stable_across_clock_advances(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);
        $ctx = $ctx->withFactRegistry(FactRegistry::rebuild($principal, $ctx));
        $tool = app(ToolRegistry::class)->get('sales.create_offer');
        $this->assertInstanceOf(ProposableTool::class, $tool);
        $this->assertInstanceOf(SalesCreateOfferTool::class, $tool);

        $args = [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ];

        Carbon::setTestNow('2026-08-01 12:00:00');
        $first = $tool->propose($principal, $args, $ctx);
        Carbon::setTestNow('2026-08-08 12:00:00');
        $second = $tool->propose($principal, $args, $ctx);
        Carbon::setTestNow();

        $this->assertSame(ToolInvocationStatus::Ok, $first->status);
        $this->assertSame(ToolInvocationStatus::Ok, $second->status);
        $this->assertSame(
            CanonicalJson::encode($first->data['payload']),
            CanonicalJson::encode($second->data['payload']),
        );
        $this->assertNotSame(
            $first->data['preview']['expires_at'] ?? null,
            $second->data['preview']['expires_at'] ?? null,
        );
    }

    #[Test]
    public function quoted_price_id_creates_offer_matching_quote_to_the_cent(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $quote = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $quote->status);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $quote, $principal);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
                'quoted_price_id' => $quote->data['price_id'],
                'quoted_tax_rate_id' => $quote->data['tax_rate_id'],
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(1, Offer::query()->count());

        $ctx = $ctx->withFactRegistry(FactRegistry::rebuild($principal, $ctx));
        $tool = app(ToolRegistry::class)->get('sales.create_offer');
        $this->assertInstanceOf(SalesCreateOfferTool::class, $tool);
        $preview = $tool->propose($principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
                'quoted_price_id' => $quote->data['price_id'],
                'quoted_tax_rate_id' => $quote->data['tax_rate_id'],
            ]],
        ], $ctx);
        $this->assertSame($quote->data['net'], $preview->data['preview']['lines'][0]['net']);
        $this->assertSame($quote->data['gross'], $preview->data['preview']['lines'][0]['gross']);
    }

    #[Test]
    public function superseded_quoted_price_id_refuses_and_writes_nothing(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $quote = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $quote, $principal);

        $old = $world['rate']->price;
        $this->assertNotNull($old);
        $old->update(['effective_to' => now()->toDateString()]);
        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $world['rate']->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => '120.00',
            'currency' => 'EUR',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $world['employee']->id,
        ]);
        $world['rate']->unsetRelation('price');

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
                'quoted_price_id' => $quote->data['price_id'],
                'quoted_tax_rate_id' => $quote->data['tax_rate_id'],
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::PriceSuperseded, $result->error?->errorCode);
        $this->assertSame('price', $result->error?->detail['superseded'] ?? null);
        $this->assertSame($quote->data['price_id'], $result->error?->detail['quoted'] ?? null);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function tax_rate_version_change_is_price_superseded_with_tax_detail(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $quote = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $quote, $principal);

        $oldTax = $world['tax'];
        $oldTax->update(['effective_to' => now()->subDay()->toDateString()]);
        TaxRate::query()->create([
            'name' => 'VAT ES reduced',
            'code' => 'vat',
            'rate' => '10.00',
            'jurisdiction' => 'ES',
            'is_default' => false,
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $world['employee']->id,
        ]);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
                'quoted_price_id' => $quote->data['price_id'],
                'quoted_tax_rate_id' => $quote->data['tax_rate_id'],
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::PriceSuperseded, $result->error?->errorCode);
        $this->assertSame('tax_rate', $result->error?->detail['superseded'] ?? null);
        $this->assertSame($quote->data['tax_rate_id'], $result->error?->detail['quoted'] ?? null);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function two_quoted_classes_detect_a_single_superseded_price(): void
    {
        $world = $this->agentPricedDeal();
        $classB = UnitClass::factory()->create([
            'tax_rate_code' => 'vat',
            'label' => 'Medium',
        ]);
        [$rateB] = $this->createUnitClassCataloguePrice(
            $classB->id,
            $world['site']->id,
            $world['employee']->id,
            ['amount' => '90.00', 'currency' => 'EUR'],
        );
        Unit::factory()->create([
            'site_id' => $world['site']->id,
            'unit_class_id' => $classB->id,
            'enabled' => true,
        ]);

        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class'], $classB);

        $quoteA = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $quoteA, $principal);
        $quoteB = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $world['site']->id,
            'unit_class_id' => $classB->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $world['site']->id,
            'unit_class_id' => $classB->id,
        ], $quoteB, $principal);

        $old = $world['rate']->price;
        $this->assertNotNull($old);
        $old->update(['effective_to' => now()->toDateString()]);
        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $world['rate']->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => '120.00',
            'currency' => 'EUR',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $world['employee']->id,
        ]);
        $world['rate']->unsetRelation('price');

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [
                [
                    'unit_class_rate_id' => $world['rate']->id,
                    'label' => 'Small unit',
                    'quoted_price_id' => $quoteA->data['price_id'],
                    'quoted_tax_rate_id' => $quoteA->data['tax_rate_id'],
                ],
                [
                    'unit_class_rate_id' => $rateB->id,
                    'label' => 'Medium unit',
                    'quoted_price_id' => $quoteB->data['price_id'],
                    'quoted_tax_rate_id' => $quoteB->data['tax_rate_id'],
                ],
            ],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::PriceSuperseded, $result->error?->errorCode);
        $this->assertSame('price', $result->error?->detail['superseded'] ?? null);
        $this->assertSame($quoteA->data['price_id'], $result->error?->detail['quoted'] ?? null);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function absent_quoted_price_id_refuses_when_a_prior_quote_exists(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $quote = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'pricing.quote', [
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
        ], $quote, $principal);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertStringContainsString('quoted_price_id', (string) $result->display);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function absent_quoted_price_id_without_prior_quote_uses_live_catalogue(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        $result = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(1, Offer::query()->count());
    }

    /**
     * @return array{site: Site, class: UnitClass, rate: UnitClassRate, deal: Deal, contact: Contact, employee: Employee, tax: TaxRate}
     */
    private function agentPricedDeal(): array
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Small']);
        [$rate] = $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => '70.00',
            'currency' => 'EUR',
        ]);
        $tax = TaxRate::query()->create([
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

        return [
            'site' => $site,
            'class' => $class,
            'rate' => $rate,
            'deal' => $deal,
            'contact' => $contact,
            'employee' => $employee,
            'tax' => $tax,
        ];
    }
}
