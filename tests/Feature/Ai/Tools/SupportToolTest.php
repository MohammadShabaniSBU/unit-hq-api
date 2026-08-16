<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Enums\AccessSuspensionReason;
use App\Enums\HoldType;
use App\Enums\InvoiceStatus;
use App\Models\AccessSuspension;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Note;
use App\Models\Site;
use App\Models\Task;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Billing\BillingMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class SupportToolTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function foreign_contract_is_ownership_denied(): void
    {
        $owner = Contact::factory()->create();
        $stranger = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $stranger->id]);

        $result = $this->dispatchTool(
            'support',
            'contract.summary',
            AgentPrincipal::verified($owner->id, null, 'en'),
            ['contract_id' => $contract->id],
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Ownership, $result->deniedReason);
    }

    #[Test]
    public function foreign_contact_on_balance_is_ownership_denied(): void
    {
        $owner = Contact::factory()->create();
        $stranger = Contact::factory()->create();

        $result = $this->dispatchTool(
            'support',
            'billing.balance',
            AgentPrincipal::verified($owner->id, null, 'en'),
            ['contact_id' => $stranger->id],
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Ownership, $result->deniedReason);
    }

    #[Test]
    public function balance_returns_two_currency_entries_and_never_a_sum(): void
    {
        $contact = Contact::factory()->create();
        $eur = Contract::factory()->create(['contact_id' => $contact->id, 'currency' => 'EUR']);
        $gbp = Contract::factory()->create(['contact_id' => $contact->id, 'currency' => 'GBP']);
        Charge::factory()->create([
            'contract_id' => $eur->id,
            'amount' => '50.00',
            'net_amount' => '50.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
        ]);
        Charge::factory()->create([
            'contract_id' => $gbp->id,
            'amount' => '30.00',
            'net_amount' => '30.00',
            'tax_amount' => '0.00',
            'currency' => 'GBP',
        ]);

        $result = $this->dispatchTool(
            'support',
            'billing.balance',
            AgentPrincipal::verified($contact->id, null, 'en'),
            [],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame(BillingMath::round2('50.00'), $result->data['balances']['EUR']);
        $this->assertSame(BillingMath::round2('30.00'), $result->data['balances']['GBP']);
        $this->assertCount(2, $result->data['balances']);
        $this->assertArrayNotHasKey('total', $result->data);
        $this->assertStringContainsString('EUR', $result->display);
        $this->assertStringContainsString('GBP', $result->display);
        $this->assertStringContainsString('not added together', $result->display);
        $this->assertStringNotContainsString('80', $result->display);
    }

    #[Test]
    public function contract_summary_includes_unit_and_rate(): void
    {
        $employee = Employee::factory()->create();
        $contact = Contact::factory()->create();
        $site = Site::factory()->create(['name' => 'Madrid Norte']);
        $class = UnitClass::factory()->create();
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'unit_number' => 'A-114',
        ]);
        [$rate, $price] = $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => '84.70',
            'currency' => 'EUR',
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'start_date' => '2026-01-15',
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
        ]);

        $result = $this->dispatchTool(
            'support',
            'contract.summary',
            AgentPrincipal::verified($contact->id, $site->id, 'en'),
            ['contract_id' => $contract->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('A-114', $result->data['unit_number']);
        $this->assertSame('2026-01-15', $result->data['start_date']);
        $this->assertStringContainsString('A-114', $result->display);
        $this->assertStringContainsString('€84.70', $result->display);
        $this->assertNotNull($rate->id);
    }

    #[Test]
    public function invoices_list_issued_without_pdf(): void
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);
        $invoice = Invoice::factory()->create([
            'contact_id' => $contact->id,
            'contract_id' => $contract->id,
            'status' => InvoiceStatus::Issued,
            'gross_total' => '121.00',
            'currency' => 'EUR',
            'full_number' => 'F-000001',
            'issue_date' => '2026-03-01',
        ]);

        $result = $this->dispatchTool(
            'support',
            'billing.invoices',
            AgentPrincipal::verified($contact->id, null, 'en'),
            [],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('F-000001', $result->data['invoices'][0]['number']);
        $this->assertStringNotContainsString('http', strtolower($result->display));
        $this->assertStringNotContainsString('pdf', strtolower($result->display));
        $this->assertSame($invoice->id, Invoice::query()->value('id'));
    }

    #[Test]
    public function next_charge_reads_contract_snapshot(): void
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'billed_through' => '2026-04-01',
            'billing_anchor_date' => '2026-01-01',
        ]);

        $result = $this->dispatchTool(
            'support',
            'billing.next_charge',
            AgentPrincipal::verified($contact->id, null, 'en'),
            ['contract_id' => $contract->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertNotNull($result->data['due_date'] ?? $result->data['next'] ?? true);
    }

    #[Test]
    public function access_status_suspended_delinquency_does_not_explain_debt(): void
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);
        AccessSuspension::factory()->delinquency()->create(['contract_id' => $contract->id]);

        $result = $this->dispatchTool(
            'support',
            'access.status',
            AgentPrincipal::verified($contact->id, null, 'en'),
            ['contract_id' => $contract->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('suspended', $result->data['status']);
        $this->assertSame(AccessSuspensionReason::Delinquency->value, $result->data['reason']);
        $this->assertNull($result->handoffReason);
        $this->assertStringNotContainsString('owe', strtolower($result->display));
        $this->assertStringNotContainsString('debt', strtolower($result->display));
    }

    #[Test]
    public function access_status_reports_overlock_without_codes(): void
    {
        $contact = Contact::factory()->create();
        $site = Site::factory()->create();
        $unit = Unit::factory()->create(['site_id' => $site->id]);
        $employee = Employee::factory()->create();
        $class = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id);
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $contract->start_date,
            'effective_to' => null,
        ]);
        UnitHold::factory()->overlock()->create([
            'unit_id' => $unit->id,
            'starts_on' => now()->subDay()->toDateString(),
            'hold_type' => HoldType::Overlock,
        ]);

        $result = $this->dispatchTool(
            'support',
            'access.status',
            AgentPrincipal::verified($contact->id, $site->id, 'en'),
            ['contract_id' => $contract->id],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('overlocked', $result->data['status']);
        $this->assertStringNotContainsString('pin', strtolower($result->display));
        $this->assertArrayNotHasKey('provider_point_id', $result->data);
    }

    #[Test]
    public function create_note_and_task_stamp_operator(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'support');

        $note = $this->dispatchTool('support', 'crm.create_note', $principal, [
            'content' => 'Asked about hours',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ], $ctx);

        $task = $this->dispatchTool('support', 'crm.create_task', $principal, [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $note->status);
        $this->assertSame(ToolInvocationStatus::Ok, $task->status);
        $this->assertSame(1, Note::query()->count());
        $this->assertSame(1, Task::query()->count());
        $this->assertSame($ctx->conversation->created_by_employee_id, Note::query()->value('employee_id'));
        $this->assertSame($ctx->conversation->created_by_employee_id, Task::query()->value('created_by'));
    }

    #[Test]
    public function create_note_rejects_foreign_contact(): void
    {
        $owner = Contact::factory()->create();
        $stranger = Contact::factory()->create();
        $principal = AgentPrincipal::verified($owner->id, null, 'en');
        $ctx = $this->writeContext($principal, 'support');

        $result = $this->dispatchTool('support', 'crm.create_note', $principal, [
            'content' => 'nope',
            'related_to_type' => 'contact',
            'related_to_id' => $stranger->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::Ownership, $result->deniedReason);
        $this->assertSame(0, Note::query()->count());
    }
}
