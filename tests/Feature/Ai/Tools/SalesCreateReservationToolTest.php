<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\PipelineSource;
use App\Enums\ReservationStatus;
use App\Models\AgentWritePolicy;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\CanonicalJson;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Guards\DisclosureGuard;
use App\Support\Ai\Guards\GroundingGuard;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\SalesCreateReservationTool;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Leasing\ReservationCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class SalesCreateReservationToolTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function propose_mode_writes_nothing(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        AgentWritePolicy::factory()->propose()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'sales.create_reservation',
        ]);
        $ctx->agent->load('writePolicies');

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::RequiresApproval, $result->deniedReason);
        $this->assertSame(CannedReply::pendingApproval('en'), $result->display);
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(0, UnitHold::query()->count());
    }

    #[Test]
    public function commit_path(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(1, UnitHold::query()->count());

        $reservation = Reservation::query()->firstOrFail();
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertSame(PipelineSource::AiAgent, $reservation->source);
        $this->assertSame($ctx->agent->id, $reservation->ai_agent_id);
        $this->assertSame($world['deal']->contact_id, $reservation->contact_id);
        $this->assertTrue($reservation->expires_at->isSameDay(ReservationCreation::defaultExpiry()));
        $this->assertSame($reservation->id, $result->data['reservation_id'] ?? null);
        $this->assertSame($reservation->unit_id, $result->data['unit_id'] ?? null);
        $this->assertSame($reservation->id, UnitHold::query()->value('reservation_id'));
        $this->assertStringNotContainsString((string) $reservation->unit->unit_number, $result->display);
    }

    #[Test]
    public function ignores_unit_id(): void
    {
        $world = $this->agentPricedDeal();
        $occupied = $world['unit'];
        $available = Unit::factory()->create([
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
            'unit_number' => 'B-99',
            'enabled' => true,
        ]);
        $this->occupy($occupied, $world['contact']);

        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
            'unit_id' => $occupied->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $reservation = Reservation::query()->firstOrFail();
        $this->assertSame($available->id, $reservation->unit_id);
        $this->assertNotSame($occupied->id, $reservation->unit_id);
    }

    #[Test]
    public function ignores_expires_at(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
            'expires_at' => now()->addDays(400)->toIso8601String(),
        ], $ctx);

        $reservation = Reservation::query()->firstOrFail();
        $this->assertTrue($reservation->expires_at->isSameDay(ReservationCreation::defaultExpiry()));
        $this->assertTrue($reservation->expires_at->lt(now()->addDays(30)));
    }

    #[Test]
    public function refuses_second_hold(): void
    {
        $world = $this->agentPricedDeal();
        Unit::factory()->create([
            'site_id' => $world['site']->id,
            'unit_class_id' => $world['class']->id,
            'enabled' => true,
        ]);
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $first = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);

        $second = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $second->status);
        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(1, UnitHold::query()->count());
    }

    #[Test]
    public function refuses_deal_without_site(): void
    {
        $world = $this->agentPricedDeal();
        $world['deal']->update(['site_id' => null]);
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(0, UnitHold::query()->count());
    }

    #[Test]
    public function no_unit_available(): void
    {
        $world = $this->agentPricedDeal();
        $this->occupy($world['unit'], $world['contact']);
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::NotFound, $result->status);
        $this->assertNull($result->handoffReason);
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(0, UnitHold::query()->count());
    }

    #[Test]
    public function anonymous_denied_verification(): void
    {
        $world = $this->agentPricedDeal();
        $principal = AgentPrincipal::anonymous($world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Verification, $result->deniedReason);
        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function fact_bag_omits_unit_identifier(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $reservation = Reservation::query()->with('unit')->firstOrFail();
        $unitNumber = (string) $reservation->unit->unit_number;
        $this->assertSame($reservation->unit_id, $result->data['unit_id'] ?? null);
        $this->assertNotContains($unitNumber, $result->facts->all());
        $this->assertNotContains(strtoupper($unitNumber), $result->facts->all());
        $this->assertStringNotContainsString($unitNumber, $result->display);

        $verdict = app(DisclosureGuard::class)->check(
            'We held unit '.$unitNumber.' for you.',
            $result->facts,
            $ctx,
        );
        $this->assertFalse($verdict->passed);
        $this->assertSame('disclosure', $verdict->blockedBy);
    }

    #[Test]
    public function licenses_expiry_and_class_label(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $this->forceCommit($ctx);

        $result = $this->dispatchTool('sales', 'sales.create_reservation', $principal, [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $expiresOn = (string) ($result->data['expires_on'] ?? '');
        $this->assertNotSame('', $expiresOn);

        $pass = app(GroundingGuard::class)->check(
            "Hold on a 10 m² unit until {$expiresOn}.",
            $result->facts,
            $ctx,
        );
        $this->assertTrue($pass->passed);

        $failDate = app(GroundingGuard::class)->check(
            'Hold on a 10 m² unit until 2099-01-01.',
            $result->facts,
            $ctx,
        );
        $this->assertFalse($failDate->passed);
        $this->assertSame('grounding', $failDate->blockedBy);

        $failClass = app(GroundingGuard::class)->check(
            "Hold on a 25 m² unit until {$expiresOn}.",
            $result->facts,
            $ctx,
        );
        $this->assertFalse($failClass->passed);
        $this->assertSame('grounding', $failClass->blockedBy);
    }

    #[Test]
    public function propose_payload_is_stable_across_clock_advances(): void
    {
        $world = $this->agentPricedDeal();
        [$principal, $ctx] = $this->channelContext($world);
        $tool = app(ToolRegistry::class)->get('sales.create_reservation');
        $this->assertInstanceOf(ProposableTool::class, $tool);
        $this->assertInstanceOf(SalesCreateReservationTool::class, $tool);

        $args = [
            'deal_id' => $world['deal']->id,
            'unit_class_id' => $world['class']->id,
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
            $first->data['preview']['expires_on'] ?? null,
            $second->data['preview']['expires_on'] ?? null,
        );
    }

    /**
     * @param  array{site: Site, class: UnitClass, rate: UnitClassRate, deal: Deal, contact: Contact, unit: Unit}  $world
     * @return array{0: AgentPrincipal, 1: AgentContext}
     */
    private function channelContext(array $world): array
    {
        $principal = AgentPrincipal::channelAsserted($world['contact']->id, $world['site']->id, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $this->licenseModels($ctx, $world['deal'], $world['class']);

        return [$principal, $ctx];
    }

    private function forceCommit(AgentContext $ctx): void
    {
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'sales.create_reservation',
            'mode' => WritePolicyMode::Commit,
        ]);
        $ctx->agent->load('writePolicies');
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
     * @return array{site: Site, class: UnitClass, rate: UnitClassRate, deal: Deal, contact: Contact, unit: Unit}
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
        [$rate] = $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
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
            'rate' => $rate,
            'deal' => $deal,
            'contact' => $contact,
            'unit' => $unit,
        ];
    }
}
