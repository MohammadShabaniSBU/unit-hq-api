<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

use App\Enums\AccessSuspensionReason;
use App\Enums\ContactChannelType;
use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceSeriesKind;
use App\Enums\InvoiceStatus;
use App\Models\AccessSuspension;
use App\Models\AiAgent;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Site;
use App\Models\SizeGuide;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\UnitOccupancy;
use App\Support\Ai\Enums\WritePolicyMode;
use Carbon\Carbon;

final class EvalWorld
{
    public const CLOCK = '2026-09-01 12:00:00';

    public Employee $operator;

    public LegalEntity $entity;

    public Site $madrid;

    public Site $london;

    public Site $empty;

    public UnitClass $smallClass;

    public Discount $discount;

    public Contact $tenantWithBalance;

    public Contact $tenantTwoCurrency;

    public Contact $tenantWithInvoices;

    public Contact $tenantDelinquent;

    public Contact $tenantNextCharge;

    public Contact $duplicateLead;

    public Contact $agentLead;

    public Deal $agentDeal;

    public UnitClassRate $madridSmallRate;

    public Contact $stranger;

    public Contract $strangerContract;

    public Contract $balanceContract;

    public Unit $unit42;

    public AiAgent $support;

    public AiAgent $sales;

    public static function freezeClock(): void
    {
        Carbon::setTestNow(self::CLOCK);
    }

    public static function seed(): self
    {
        $world = new self;
        $world->operator = Employee::factory()->create([
            'first_name' => 'Eval',
            'last_name' => 'Operator',
            'email' => 'eval-operator@keevaris.test',
        ]);

        $es = Country::query()->firstOrCreate(['code' => 'ES'], ['name' => 'Spain']);
        $gb = Country::query()->firstOrCreate(['code' => 'GB'], ['name' => 'United Kingdom']);

        $world->entity = LegalEntity::factory()->create([
            'legal_name' => 'Eval Entity',
            'tax_id' => 'B00000001',
            'country_code' => 'ES',
        ]);

        $world->madrid = Site::factory()->create([
            'name' => 'Madrid Norte',
            'code' => 'MAD-EV',
            'country_id' => $es->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'legal_entity_id' => $world->entity->id,
        ]);
        $world->london = Site::factory()->create([
            'name' => 'London East',
            'code' => 'LON-EV',
            'country_id' => $gb->id,
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'legal_entity_id' => $world->entity->id,
        ]);
        $world->empty = Site::factory()->create([
            'name' => 'Empty Site',
            'code' => 'EMP-EV',
            'country_id' => $es->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'legal_entity_id' => $world->entity->id,
        ]);

        $world->smallClass = UnitClass::factory()->create([
            'code' => 'SM',
            'label' => 'Small',
            'size' => 6,
            'tax_rate_code' => 'vat',
        ]);

        $world->cataloguePrice($world->madrid, '70.00', 'EUR');
        $world->cataloguePrice($world->london, '80.00', 'GBP');
        $world->madridSmallRate = UnitClassRate::query()
            ->where('site_id', $world->madrid->id)
            ->where('unit_class_id', $world->smallClass->id)
            ->firstOrFail();

        TaxRate::query()->create([
            'name' => 'VAT ES',
            'code' => 'vat',
            'rate' => '21.00',
            'jurisdiction' => 'ES',
            'is_default' => true,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $world->operator->id,
        ]);
        TaxRate::query()->create([
            'name' => 'VAT GB',
            'code' => 'vat',
            'rate' => '20.00',
            'jurisdiction' => 'GB',
            'is_default' => false,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $world->operator->id,
        ]);

        $world->discount = Discount::factory()->percent('15.00')->create([
            'name' => 'Catalogue 15',
            'created_by' => $world->operator->id,
        ]);

        SizeGuide::factory()->create([
            'metric' => 'standard_boxes',
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
            'notes' => 'Conservative: 20–24 standard boxes need more than a 5–8 m² unit.',
        ]);

        Unit::factory()->count(3)->sequence(
            ['unit_number' => 'EV-001'],
            ['unit_number' => 'EV-002'],
            ['unit_number' => 'EV-003'],
        )->create([
            'site_id' => $world->madrid->id,
            'unit_class_id' => $world->smallClass->id,
            'enabled' => true,
        ]);

        $world->stranger = Contact::factory()->create([
            'first_name' => 'Other',
            'last_name' => 'Occupant',
            'email' => 'other-occupant@keevaris.test',
        ]);
        $world->unit42 = Unit::factory()->create([
            'site_id' => $world->madrid->id,
            'unit_class_id' => $world->smallClass->id,
            'unit_number' => 'A-42',
            'enabled' => true,
        ]);
        $world->strangerContract = Contract::factory()->create([
            'contact_id' => $world->stranger->id,
            'currency' => 'EUR',
            'start_date' => '2026-01-01',
        ]);
        $strangerPrice = $world->itemPrice();
        ContractItem::query()->create([
            'contract_id' => $world->strangerContract->id,
            'item_type' => 'unit',
            'item_id' => $world->unit42->id,
            'price_id' => $strangerPrice->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::factory()->create([
            'unit_id' => $world->unit42->id,
            'contract_id' => $world->strangerContract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        $world->tenantWithBalance = Contact::factory()->create([
            'first_name' => 'Elena',
            'last_name' => 'Balance',
            'email' => 'elena-balance@keevaris.test',
        ]);
        $world->balanceContract = $world->contractWithCharge($world->tenantWithBalance, '84.70', 'EUR');

        $world->tenantTwoCurrency = Contact::factory()->create([
            'first_name' => 'Two',
            'last_name' => 'Currency',
            'email' => 'two-currency@keevaris.test',
        ]);
        $world->contractWithCharge($world->tenantTwoCurrency, '50.00', 'EUR');
        $world->contractWithCharge($world->tenantTwoCurrency, '30.00', 'GBP');

        $world->tenantWithInvoices = Contact::factory()->create([
            'first_name' => 'Invoiced',
            'last_name' => 'Tenant',
            'email' => 'invoiced@keevaris.test',
        ]);
        $invoiceContract = Contract::factory()->create([
            'contact_id' => $world->tenantWithInvoices->id,
            'currency' => 'EUR',
        ]);
        $series = InvoiceSeries::query()
            ->where('legal_entity_id', $world->entity->id)
            ->where('kind', InvoiceSeriesKind::Ordinary)
            ->where('is_default', true)
            ->firstOrFail();
        Invoice::query()->create([
            'legal_entity_id' => $world->entity->id,
            'invoice_series_id' => $series->id,
            'number' => 1,
            'full_number' => 'F-000001',
            'kind' => InvoiceKind::Ordinary,
            'status' => InvoiceStatus::Issued,
            'issue_date' => '2026-03-01',
            'contract_id' => $invoiceContract->id,
            'contact_id' => $world->tenantWithInvoices->id,
            'currency' => 'EUR',
            'net_total' => '100.00',
            'tax_total' => '21.00',
            'gross_total' => '121.00',
            'issuer_name' => $world->entity->legal_name,
            'issuer_tax_id' => $world->entity->tax_id,
            'issuer_address' => [
                'line1' => $world->entity->address_line1,
                'line2' => $world->entity->address_line2,
                'city' => $world->entity->city,
                'postal' => $world->entity->postal_code,
                'country' => $world->entity->country_code,
            ],
            'buyer_name' => 'Invoiced Tenant',
            'buyer_tax_id' => null,
            'buyer_address' => null,
            'created_by' => $world->operator->id,
        ]);

        $world->tenantDelinquent = Contact::factory()->create([
            'first_name' => 'Delia',
            'last_name' => 'Quent',
            'email' => 'delinquent@keevaris.test',
        ]);
        $delinquentContract = Contract::factory()->create([
            'contact_id' => $world->tenantDelinquent->id,
            'currency' => 'EUR',
        ]);
        $delinquency = Delinquency::factory()->create([
            'contract_id' => $delinquentContract->id,
        ]);
        AccessSuspension::factory()->create([
            'contract_id' => $delinquentContract->id,
            'reason' => AccessSuspensionReason::Delinquency,
            'delinquency_id' => $delinquency->id,
        ]);

        $world->tenantNextCharge = Contact::factory()->create([
            'first_name' => 'Next',
            'last_name' => 'Charge',
            'email' => 'next-charge@keevaris.test',
        ]);
        $nextContract = Contract::factory()->create([
            'contact_id' => $world->tenantNextCharge->id,
            'currency' => 'EUR',
            'start_date' => '2026-01-01',
            'billing_anchor_date' => '2026-01-01',
            'billed_through' => '2026-08-01',
        ]);
        $nextUnit = Unit::factory()->create([
            'site_id' => $world->madrid->id,
            'unit_class_id' => $world->smallClass->id,
            'unit_number' => 'EV-NEXT',
            'enabled' => true,
        ]);
        ContractItem::query()->create([
            'contract_id' => $nextContract->id,
            'item_type' => 'unit',
            'item_id' => $nextUnit->id,
            'price_id' => $world->itemPrice()->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $world->duplicateLead = Contact::factory()->create([
            'first_name' => 'Dup',
            'last_name' => 'Lead',
            'email' => 'dup-lead@keevaris.test',
        ]);
        ContactChannel::query()->create([
            'contact_id' => $world->duplicateLead->id,
            'type' => ContactChannelType::Email,
            'value' => 'dup-lead@keevaris.test',
            'is_primary' => true,
        ]);

        $world->agentLead = Contact::factory()->create([
            'first_name' => 'Agent',
            'last_name' => 'Lead',
            'email' => 'agent-lead@keevaris.test',
            'source' => ContactSource::AiAgent,
        ]);
        $world->agentDeal = Deal::factory()->create([
            'contact_id' => $world->agentLead->id,
            'site_id' => $world->madrid->id,
            'status' => DealStatus::Qualified,
            'desired_unit_class_id' => $world->smallClass->id,
        ]);

        $model = (string) config('agents.default_model');
        $world->support = AiAgent::query()->updateOrCreate(
            ['key' => 'support'],
            ['name' => 'Support Agent', 'model' => $model, 'is_active' => true],
        );
        $world->sales = AiAgent::query()->updateOrCreate(
            ['key' => 'sales'],
            ['name' => 'Sales Agent', 'model' => $model, 'is_active' => true],
        );

        $world->sales->writePolicies()->updateOrCreate(
            ['tool_key' => 'sales.create_offer'],
            [
                'mode' => WritePolicyMode::Commit,
                'max_per_conversation' => 2,
                'max_per_day' => 50,
            ],
        );
        $world->sales->writePolicies()->updateOrCreate(
            ['tool_key' => 'sales.create_reservation'],
            [
                'mode' => WritePolicyMode::Propose,
                'max_per_conversation' => 1,
                'max_per_day' => 20,
            ],
        );

        return $world;
    }

    public function contact(string $key): ?Contact
    {
        $key = str_starts_with($key, 'fixture.') ? $key : 'fixture.'.$key;

        return match ($key) {
            'fixture.tenant_with_balance' => $this->tenantWithBalance,
            'fixture.tenant_two_currency' => $this->tenantTwoCurrency,
            'fixture.tenant_with_invoices' => $this->tenantWithInvoices,
            'fixture.tenant_delinquent' => $this->tenantDelinquent,
            'fixture.tenant_next_charge' => $this->tenantNextCharge,
            'fixture.duplicate_lead' => $this->duplicateLead,
            'fixture.agent_lead' => $this->agentLead,
            'fixture.stranger' => $this->stranger,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function replacements(): array
    {
        return [
            '{{madrid.id}}' => (string) $this->madrid->id,
            '{{london.id}}' => (string) $this->london->id,
            '{{empty.id}}' => (string) $this->empty->id,
            '{{small_class.id}}' => (string) $this->smallClass->id,
            '{{discount.id}}' => (string) $this->discount->id,
            '{{tenant_with_balance.id}}' => (string) $this->tenantWithBalance->id,
            '{{tenant_two_currency.id}}' => (string) $this->tenantTwoCurrency->id,
            '{{tenant_with_invoices.id}}' => (string) $this->tenantWithInvoices->id,
            '{{tenant_delinquent.id}}' => (string) $this->tenantDelinquent->id,
            '{{tenant_next_charge.id}}' => (string) $this->tenantNextCharge->id,
            '{{duplicate_lead.id}}' => (string) $this->duplicateLead->id,
            '{{agent_lead.id}}' => (string) $this->agentLead->id,
            '{{agent_deal.id}}' => (string) $this->agentDeal->id,
            '{{madrid_small_rate.id}}' => (string) $this->madridSmallRate->id,
            '{{stranger.id}}' => (string) $this->stranger->id,
            '{{stranger_contract.id}}' => (string) $this->strangerContract->id,
            '{{balance_contract.id}}' => (string) $this->balanceContract->id,
        ];
    }

    public function agent(string $key): AiAgent
    {
        return $key === 'sales' ? $this->sales : $this->support;
    }

    private function cataloguePrice(Site $site, string $amount, string $currency): Price
    {
        $rate = UnitClassRate::query()->firstOrCreate([
            'unit_class_id' => $this->smallClass->id,
            'site_id' => $site->id,
        ]);

        return Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $rate->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => $amount,
            'currency' => $currency,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_by' => $this->operator->id,
        ]);
    }

    private function itemPrice(): Price
    {
        return Price::query()->create([
            'priceable_type' => null,
            'priceable_id' => null,
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => '84.70',
            'currency' => 'EUR',
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->operator->id,
        ]);
    }

    private function contractWithCharge(Contact $contact, string $amount, string $currency): Contract
    {
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => $currency,
        ]);
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => $currency,
        ]);

        return $contract;
    }
}
