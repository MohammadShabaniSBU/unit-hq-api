<?php

declare(strict_types=1);

namespace Tests\Fixtures\Agents\ToolResults;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\HandoffReason as AgentHandoffReason;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;

/**
 * One seeded world and per-tool arguments for ToolResultContractTest.
 *
 * @phpstan-type Fixture array{
 *     agent: string,
 *     principal: AgentPrincipal,
 *     arguments: array<string, mixed>,
 *     ctx: AgentContext|null
 * }
 */
final class RegistryFixtures
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;

    public Site $site;

    public UnitClass $class;

    public UnitClassRate $rate;

    public Contact $contact;

    public Deal $deal;

    public Contract $contract;

    public Employee $employee;

    public function seed(): void
    {
        $this->employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'name' => 'Madrid Centro',
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
        ]);
        $this->class = UnitClass::factory()->create([
            'label' => 'Trastero 8 m²',
            'size' => 8,
            'tax_rate_code' => 'vat',
        ]);
        [$this->rate] = $this->createUnitClassCataloguePrice($this->class->id, $this->site->id, $this->employee->id, [
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
            'created_by' => $this->employee->id,
        ]);
        Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->class->id,
            'enabled' => true,
        ]);
        Discount::factory()->create(['name' => 'Spring promo']);
        $this->contact = Contact::factory()->create([
            'source' => ContactSource::AiAgent,
            'first_name' => 'Ana',
            'last_name' => 'Ruiz',
        ]);
        $this->deal = Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $this->site->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $this->class->id,
        ]);
        $this->contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
        ]);
        Invoice::factory()->create([
            'contact_id' => $this->contact->id,
            'contract_id' => $this->contract->id,
        ]);
    }

    /**
     * @return Fixture
     */
    public function for(string $toolKey): array
    {
        $anon = AgentPrincipal::anonymous($this->site->id, 'en');
        $verified = AgentPrincipal::verified($this->contact->id, $this->site->id, 'en');
        $asserted = AgentPrincipal::channelAsserted($this->contact->id, $this->site->id, 'en');

        return match ($toolKey) {
            'facility.availability' => $this->sales($anon, [
                'site_id' => $this->site->id,
                'unit_class_id' => $this->class->id,
            ]),
            'facility.site_info' => $this->sales($anon, ['site_id' => $this->site->id]),
            'pricing.quote' => $this->sales($anon, [
                'site_id' => $this->site->id,
                'unit_class_id' => $this->class->id,
            ]),
            'pricing.discounts' => $this->sales($anon, ['site_id' => $this->site->id]),
            'sales.propose_offer' => $this->sales($anon, [
                'site_id' => $this->site->id,
                'unit_class_id' => $this->class->id,
            ]),
            'sales.create_offer' => $this->sales($anon, [
                'deal_id' => $this->deal->id,
                'options' => [[
                    'unit_class_rate_id' => $this->rate->id,
                    'label' => $this->class->label,
                ]],
            ], $this->writeContext($anon, 'sales', $this->employee)),
            'sales.create_reservation' => $this->sales($asserted, [
                'deal_id' => $this->deal->id,
                'unit_class_id' => $this->class->id,
            ], $this->writeContext($asserted, 'sales', $this->employee)),
            'crm.create_contact' => $this->sales($anon, ['first_name' => 'Luis']),
            'crm.create_deal' => $this->sales($anon, ['contact_id' => $this->contact->id]),
            'crm.create_task' => $this->sales($anon, [
                'title' => 'Follow up',
                'related_to_type' => 'contact',
                'related_to_id' => $this->contact->id,
            ], $this->writeContext($anon, 'sales', $this->employee)),
            'crm.create_note' => [
                'agent' => 'support',
                'principal' => $asserted,
                'arguments' => [
                    'content' => 'Called the prospect.',
                    'related_to_type' => 'contact',
                    'related_to_id' => $this->contact->id,
                ],
                'ctx' => $this->writeContext($asserted, 'support', $this->employee),
            ],
            'contract.summary' => $this->support($verified, ['contract_id' => $this->contract->id]),
            'billing.balance' => $this->support($verified, []),
            'billing.next_charge' => $this->support($verified, ['contract_id' => $this->contract->id]),
            'billing.invoices' => $this->support($verified, []),
            'access.status' => $this->support($verified, ['contract_id' => $this->contract->id]),
            'kb.faq_lookup' => $this->sales($anon, ['key' => 'access_hours', 'site_id' => $this->site->id]),
            'agent.escalate' => $this->sales($anon, [
                'reason' => AgentHandoffReason::CustomerRequested->value,
                'summary' => 'Customer asked for a person.',
            ]),
            default => throw new \InvalidArgumentException("No fixture for tool [{$toolKey}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return Fixture
     */
    private function sales(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): array
    {
        return [
            'agent' => 'sales',
            'principal' => $principal,
            'arguments' => $arguments,
            'ctx' => $ctx,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return Fixture
     */
    private function support(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): array
    {
        return [
            'agent' => 'support',
            'principal' => $principal,
            'arguments' => $arguments,
            'ctx' => $ctx,
        ];
    }
}
