<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Models\AgentConversationMessage;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\ArgumentProvenance;
use App\Support\Ai\Guards\UserStatedIdentifiers;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactRegistry;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\Ai\SpyTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ArgumentProvenanceTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function user_stated_site_id_licenses_site_info(): void
    {
        $site = Site::factory()->create();
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->persistUser($ctx->conversation->id, "give me the details of site with id {$site->id}", 1);
        app(ToolDispatcher::class)->beginTurn();

        $result = $this->dispatchTool('sales', 'facility.site_info', $principal, [
            'site_id' => $site->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame($site->id, $result->data['site_id']);
    }

    #[Test]
    public function prior_ok_availability_licenses_returned_class_not_a_guess(): void
    {
        [$site, $class1] = $this->pricedClass();
        $class2 = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Large']);
        $this->createUnitClassCataloguePrice(
            $class2->id,
            $site->id,
            Employee::factory()->create()->id,
            ['amount' => '90.00', 'currency' => 'EUR'],
        );
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class2->id,
            'enabled' => true,
        ]);

        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseEntities($ctx, [EntityRef::unitClass($class1, $site)]);

        $ok = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $site->id,
            'unit_class_id' => $class1->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $ok->status);

        $denied = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $site->id,
            'unit_class_id' => $class2->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Denied, $denied->status);
        $this->assertSame(ToolDeniedReason::UnlicensedArgument, $denied->deniedReason);
        $this->assertSame(ToolErrorCode::UnlicensedArgument, $denied->error?->errorCode);
        $this->assertSame('facility.availability', $denied->error?->recovery['tool'] ?? null);
        $this->assertArrayNotHasKey('gross', $denied->data);
    }

    #[Test]
    public function registry_rebuilds_from_trace_after_memo_reset(): void
    {
        [$site, $class] = $this->pricedClass();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseEntities($ctx, [EntityRef::unitClass($class, $site)]);
        app(ArgumentProvenance::class)->resetMemo();

        $result = $this->dispatchTool('sales', 'pricing.quote', $principal, [
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertTrue(FactRegistry::rebuild($principal, $ctx)->contains(EntityType::UnitClass, $class->id));
    }

    #[Test]
    public function create_offer_rate_whose_class_is_unlicensed_is_denied(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal']);

        $missing = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => 999999,
                'label' => 'Ghost',
            ]],
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Denied, $missing->status);
        $this->assertSame(ToolDeniedReason::UnlicensedArgument, $missing->deniedReason);
        $this->assertSame(0, Offer::query()->count());

        $real = $this->dispatchTool('sales', 'sales.create_offer', $principal, [
            'deal_id' => $world['deal']->id,
            'options' => [[
                'unit_class_rate_id' => $world['rate']->id,
                'label' => 'Small unit',
            ]],
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Denied, $real->status);
        $this->assertSame(ToolDeniedReason::UnlicensedArgument, $real->deniedReason);
        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function unknown_related_to_type_is_invalid_arguments(): void
    {
        $spy = new SpyTool(
            key: 'test.morph',
            required: VerificationLevel::Anonymous,
            contactKeys: [],
            throwOnHandle: true,
            schema: [
                'related_to_type' => [
                    'type' => 'string',
                    'required' => true,
                    'enum' => ['contact', 'deal', 'insurance'],
                    'description' => 'Parent morph alias',
                ],
                'related_to_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Parent id',
                ],
            ],
            entityArguments: ['related_to_id' => 'related_to_type'],
        );
        app(ToolRegistry::class)->register($spy);
        $definition = new TestAgentDefinition('test-morph', ['test.morph']);
        app(AgentRegistry::class)->register($definition);

        $result = app(ToolDispatcher::class)->dispatch(
            $definition,
            AgentPrincipal::anonymous(null, 'en'),
            'test.morph',
            ['related_to_type' => 'insurance', 'related_to_id' => 1],
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertFalse($spy->handleCalled);
    }

    #[Test]
    public function null_site_id_is_skipped_not_denied(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $result = $this->dispatchTool('sales', 'facility.site_info', $principal, [
            'site_id' => null,
        ], $ctx);

        $this->assertNotSame(ToolDeniedReason::UnlicensedArgument, $result->deniedReason);
        $this->assertSame(ToolErrorCode::SiteUnresolved, $result->error?->errorCode);
    }

    #[Test]
    public function unit_number_is_site_scoped_when_conversation_has_a_site(): void
    {
        $here = Site::factory()->create();
        $there = Site::factory()->create();
        Unit::factory()->create([
            'site_id' => $there->id,
            'unit_number' => 'A-114',
            'enabled' => true,
        ]);

        $this->assertSame([], UserStatedIdentifiers::extract('unit A-114', $here->id));
        $refs = UserStatedIdentifiers::extract('unit A-114', null);
        $this->assertCount(1, $refs);
        $this->assertSame(EntityType::Unit, $refs[0]->type);
    }

    #[Test]
    public function next_turn_user_stated_id_is_licensed_after_begin_turn(): void
    {
        $first = Site::factory()->create();
        $second = Site::factory()->create();
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $this->persistUser($ctx->conversation->id, "give me the details of site with id {$first->id}", 1);
        app(ToolDispatcher::class)->beginTurn();
        $okFirst = $this->dispatchTool('sales', 'facility.site_info', $principal, [
            'site_id' => $first->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $okFirst->status);

        $this->persistUser($ctx->conversation->id, "site with id {$second->id}", 2);
        app(ToolDispatcher::class)->beginTurn();
        $okSecond = $this->dispatchTool('sales', 'facility.site_info', $principal, [
            'site_id' => $second->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $okSecond->status);
    }

    /**
     * @return array{0: Site, 1: UnitClass}
     */
    private function pricedClass(): array
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Small']);
        $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
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

        return [$site, $class];
    }

    /**
     * @return array{site: Site, class: UnitClass, rate: \App\Models\UnitClassRate, deal: Deal, contact: Contact}
     */
    private function agentPricedDeal(): array
    {
        [$site, $class] = $this->pricedClass();
        $rate = \App\Models\UnitClassRate::query()
            ->where('site_id', $site->id)
            ->where('unit_class_id', $class->id)
            ->firstOrFail();
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
        ];
    }

    private function persistUser(int $conversationId, string $body, int $sequence): void
    {
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversationId,
            'sequence' => $sequence,
            'role' => AgentMessageRole::User,
            'content' => $body,
        ]);
    }
}
