<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Models\AgentWritePolicy;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Tools\ToolRegistry;
use Tests\Support\CreatesCataloguePrices;

trait SetsUpProposableReservation
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;

    protected ProposableReservationTool $reservationTool;

    protected Site $site;

    protected UnitClass $unitClass;

    protected Deal $deal;

    protected Contact $contact;

    protected Employee $employee;

    protected Unit $unit;

    protected function setUpProposableReservation(AgentOrigin $origin = AgentOrigin::Inbox): AgentContext
    {
        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->unitClass = UnitClass::factory()->create();
        $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->contact = Contact::factory()->create();
        $this->deal = Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $this->site->id,
            'desired_unit_class_id' => $this->unitClass->id,
        ]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'enabled' => true,
        ]);

        $this->reservationTool = new ProposableReservationTool;
        app(ToolRegistry::class)->register($this->reservationTool);
        app(AgentRegistry::class)->register(new TestAgentDefinition('test-reserve', ['test.create_reservation']));

        $principal = AgentPrincipal::anonymous($this->site->id, 'en');
        $ctx = $this->writeContext($principal, 'test-reserve', $this->employee, $origin);
        app(AgentRegistry::class)->register(new TestAgentDefinition($ctx->agent->key, ['test.create_reservation']));
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'test.create_reservation',
            'mode' => WritePolicyMode::Propose,
        ]);
        $ctx->agent->load('writePolicies');

        return $ctx;
    }

    /**
     * @return array{deal_id: int, unit_class_id: int}
     */
    protected function reservationArgs(): array
    {
        return [
            'deal_id' => $this->deal->id,
            'unit_class_id' => $this->unitClass->id,
        ];
    }
}
