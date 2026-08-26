<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Models\AgentConversation;
use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SizeGuide;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Guards\ForbiddenClaimGuard;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\SalesCreateReservationTool;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Facility\SizeGuideResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ForbiddenClaimLicensingTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    private FakeModelDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);
    }

    #[Test]
    public function unlicensed_reserved_claim_is_blocked(): void
    {
        $verdict = app(ForbiddenClaimGuard::class)->check(
            "It's reserved.",
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales'),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('forbidden_claim', $verdict->blockedBy);
    }

    #[Test]
    public function licensed_committed_turn_passes_and_licence_does_not_survive(): void
    {
        $world = $this->agentPricedDeal();
        $conversation = $this->salesConversation($world);
        $this->forceCommit($conversation);
        $this->licenseModels($this->contextFor($conversation), $world['deal'], $world['class']);

        $this->driver
            ->enqueueToolCalls([[
                'name' => 'sales.create_reservation',
                'id' => 'c1',
                'arguments' => [
                    'deal_id' => $world['deal']->id,
                    'unit_class_id' => $world['class']->id,
                ],
            ]])
            ->enqueueText("It's reserved.");

        $first = app(AgentRuntime::class)->turn(
            $conversation,
            $conversation->principal(),
            'Please hold a small unit.',
        );

        $this->assertNull($first->blockedBy);
        $this->assertNull($first->handoff);
        $this->assertStringContainsString("It's reserved", $first->draft);

        $this->driver->enqueueText("It's reserved.");
        $this->driver->enqueueText("It's reserved.");
        $this->driver->enqueueText("It's reserved.");

        $second = app(AgentRuntime::class)->turn(
            $conversation->fresh(),
            $conversation->principal(),
            "It's still reserved, right?",
        );

        $this->assertSame('forbidden_claim', $second->blockedBy);
    }

    #[Test]
    public function propose_never_licenses(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $tool = app(ToolRegistry::class)->get('sales.create_reservation');
        $this->assertInstanceOf(ProposableTool::class, $tool);
        $this->assertInstanceOf(SalesCreateReservationTool::class, $tool);

        $result = $tool->propose($principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame([], $result->licensedClaims);
    }

    #[Test]
    public function not_found_never_licenses(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommitOnContext($ctx);
        $this->occupy($world['unit'], $world['contact']);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::NotFound, $result->status);
        $this->assertSame([], $result->licensedClaims);
    }

    #[Test]
    public function idempotent_replay_does_not_relicense(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommitOnContext($ctx);
        $args = [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ];

        $first = $this->dispatchTool('sales', 'sales.create_reservation', $principal, $args, $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);
        $this->assertSame([ForbiddenClaimKey::AvailabilityGuarantee], $first->licensedClaims);
        $this->recordInvocation($ctx, 'sales.create_reservation', $args, $first, $principal);

        $second = $this->dispatchTool('sales', 'sales.create_reservation', $principal, $args, $ctx);
        $this->assertTrue($second->replayed);
        $this->assertSame([], $second->licensedClaims);
    }

    #[Test]
    public function unlicensed_capacity_claim_is_blocked_for_sales_and_support(): void
    {
        foreach (['sales', 'support'] as $agent) {
            $verdict = app(ForbiddenClaimGuard::class)->check(
                'For 20–24 standard boxes, a unit around 5–8 m² should work well.',
                new FactBag,
                $this->writeContext(AgentPrincipal::anonymous(null, 'en'), $agent),
            );

            $this->assertFalse($verdict->passed, $agent);
            $this->assertSame('forbidden_claim', $verdict->blockedBy, $agent);
        }
    }

    #[Test]
    public function size_guide_licenses_capacity_this_turn_only(): void
    {
        $site = Site::factory()->create();
        SizeGuide::factory()->create([
            'metric' => 'standard_boxes',
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
        ]);

        $agent = AiAgent::factory()->create([
            'key' => 'sales',
            'name' => 'sales',
            'is_active' => true,
        ]);
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'site_id' => $site->id,
            'locale' => 'en',
        ]);

        $this->driver
            ->enqueueToolCalls([[
                'name' => 'facility.size_guide',
                'id' => 'g1',
                'arguments' => [
                    'metric' => 'standard_boxes',
                    'quantity' => 24,
                    'site_id' => $site->id,
                ],
            ]])
            ->enqueueText('For 17–28 standard boxes, a unit around 12–16 m² should work well. '.SizeGuideResolver::DISCLAIMER);

        $first = app(AgentRuntime::class)->turn(
            $conversation,
            AgentPrincipal::anonymous($site->id, 'en'),
            'I have about 24 boxes.',
        );

        $this->assertNull($first->blockedBy);
        $this->assertStringContainsString('12–16', $first->draft);
        $this->assertStringContainsString(SizeGuideResolver::DISCLAIMER, $first->draft);

        $this->driver->enqueueText('For 17–28 standard boxes, a unit around 12–16 m² should work well.');
        $this->driver->enqueueText('For 17–28 standard boxes, a unit around 12–16 m² should work well.');
        $this->driver->enqueueText('For 17–28 standard boxes, a unit around 12–16 m² should work well.');

        $second = app(AgentRuntime::class)->turn(
            $conversation->fresh(),
            AgentPrincipal::anonymous($site->id, 'en'),
            'So a small unit then?',
        );

        $this->assertSame('forbidden_claim', $second->blockedBy);
    }

    /**
     * @param  array{site: Site, class: UnitClass, deal: Deal, contact: Contact, unit: Unit}  $world
     */
    private function salesConversation(array $world): AgentConversation
    {
        $agent = AiAgent::factory()->create([
            'key' => 'sales',
            'name' => 'sales',
            'is_active' => true,
        ]);

        return AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'contact_id' => $world['contact']->id,
            'site_id' => $world['site']->id,
            'verification_level' => VerificationLevel::ChannelAsserted,
            'locale' => 'en',
        ]);
    }

    private function contextFor(AgentConversation $conversation): AgentContext
    {
        $conversation->loadMissing('aiAgent');

        return new AgentContext(
            $conversation->principal(),
            ChannelProfile::for($conversation->channel),
            app(AgentRegistry::class)->get('sales'),
            $conversation,
            $conversation->aiAgent,
        );
    }

    private function forceCommit(AgentConversation $conversation): void
    {
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $conversation->ai_agent_id,
            'tool_key' => 'sales.create_reservation',
            'mode' => WritePolicyMode::Commit,
        ]);
        $conversation->load('aiAgent.writePolicies');
    }

    private function forceCommitOnContext(AgentContext $ctx): void
    {
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'sales.create_reservation',
            'mode' => WritePolicyMode::Commit,
        ]);
        $ctx->agent->load('writePolicies');
    }

    /**
     * @param  array{site: Site, class: UnitClass, deal: Deal, contact: Contact, unit: Unit}  $world
     * @return array{0: AgentPrincipal, 1: AgentContext}
     */
    private function channelContext(array $world): array
    {
        $principal = AgentPrincipal::channelAsserted($world['contact']->id, $world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        return [$principal, $ctx];
    }

    private function occupy(Unit $unit, Contact $contact): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
    }

    /**
     * @return array{site: Site, class: UnitClass, deal: Deal, contact: Contact, unit: Unit}
     */
    private function agentPricedDeal(): array
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
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'unit_number' => 'A-42',
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
            'deal' => $deal,
            'contact' => $contact,
            'unit' => $unit,
        ];
    }
}
