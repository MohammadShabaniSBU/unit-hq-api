<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\UnitOccupancy;
use App\Support\Auth\Permission;
use App\Support\Billing\BillingMath;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\RbacFixture;
use Tests\TestCase;

/**
 * S17-06 narrative walk: four employees on RbacFixture.
 * Named tests cover each acceptance step; spine_runs_within_budget runs the
 * full walk on one fixture boot and enforces the 30s ceiling.
 */
class RbacSpineTest extends TestCase
{
    use RefreshDatabase;

    private RbacFixture $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = RbacFixture::create($this);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function leasing_agent_signs_at_granted_site(): void
    {
        $this->step_leasing_agent_signs_at_granted_site();
    }

    #[Test]
    public function denied_signing_writes_nothing(): void
    {
        $this->step_denied_signing_writes_nothing();
    }

    #[Test]
    public function out_of_scope_record_is_404(): void
    {
        Sanctum::actingAs($this->fx->ana);
        $this->getJson('/api/contracts/'.$this->fx->contractB1Id)->assertNotFound();

        $list = $this->getJson('/api/contracts?per_page=100')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->fx->contractA1Id, $ids);
        $this->assertNotContains($this->fx->contractB1Id, $ids);

        $board = $this->getJson('/api/contracts/board')->assertOk();
        $boardTotal = (int) collect($board->json('data.columns'))->sum('total');
        $this->assertSame((int) $list->json('meta.total'), $boardTotal);

        $this->postJson('/api/billing-runs', [])
            ->assertForbidden()
            ->assertJsonPath('data.permission', Permission::BillingRunExecute->value);
    }

    #[Test]
    public function accountant_issues_invoice_cannot_sign(): void
    {
        $this->step_accountant_issues_invoice_cannot_sign();
    }

    #[Test]
    public function regrant_applies_without_relogin(): void
    {
        Sanctum::actingAs($this->fx->siteManager);
        $this->postJson('/api/delinquencies/'.$this->fx->delinquencyA->id.'/pause', [
            'reason' => 'dispute',
        ])->assertOk();
        $this->postJson('/api/delinquencies/'.$this->fx->delinquencyB->id.'/pause', [
            'reason' => 'dispute',
        ])->assertNotFound();

        $this->step_regrant_applies_without_relogin();
    }

    #[Test]
    public function last_owner_protected(): void
    {
        $this->step_last_owner_protected();
    }

    #[Test]
    public function cross_site_contact_visible_with_full_history(): void
    {
        Sanctum::actingAs($this->fx->ana);

        $list = $this->getJson('/api/contacts?per_page=100')->assertOk();
        $this->assertContains(
            $this->fx->dualSiteContact->id,
            collect($list->json('data'))->pluck('id')->all(),
        );

        $detail = $this->getJson('/api/contacts/'.$this->fx->dualSiteContact->id)->assertOk();
        $contractIds = collect($detail->json('data.contracts'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->fx->contractA1Id, $contractIds);
        $this->assertContains($this->fx->contractB1Id, $contractIds);
    }

    #[Test]
    public function spine_runs_within_budget(): void
    {
        $started = hrtime(true);

        $this->step_leasing_agent_signs_at_granted_site();
        $this->step_denied_signing_writes_nothing();

        Sanctum::actingAs($this->fx->ana);
        $list = $this->getJson('/api/contracts?per_page=100')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->fx->contractA1Id, $ids);
        $this->assertNotContains($this->fx->contractB1Id, $ids);
        $board = $this->getJson('/api/contracts/board')->assertOk();
        $this->assertSame(
            (int) $list->json('meta.total'),
            (int) collect($board->json('data.columns'))->sum('total'),
        );
        $this->getJson('/api/contracts/'.$this->fx->contractB1Id)->assertNotFound();
        $this->postJson('/api/billing-runs', [])
            ->assertForbidden()
            ->assertJsonPath('data.permission', Permission::BillingRunExecute->value);

        $this->step_accountant_issues_invoice_cannot_sign();

        Sanctum::actingAs($this->fx->siteManager);
        $this->postJson('/api/delinquencies/'.$this->fx->delinquencyA->id.'/pause', [
            'reason' => 'dispute',
        ])->assertOk();
        $this->postJson('/api/delinquencies/'.$this->fx->delinquencyB->id.'/pause', [
            'reason' => 'dispute',
        ])->assertNotFound();

        $this->step_regrant_applies_without_relogin();
        $this->step_last_owner_protected();

        Sanctum::actingAs($this->fx->ana);
        $contacts = $this->getJson('/api/contacts?per_page=100')->assertOk();
        $this->assertContains(
            $this->fx->dualSiteContact->id,
            collect($contacts->json('data'))->pluck('id')->all(),
        );
        $detail = $this->getJson('/api/contacts/'.$this->fx->dualSiteContact->id)->assertOk();
        $contractIds = collect($detail->json('data.contracts'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->fx->contractA1Id, $contractIds);
        $this->assertContains($this->fx->contractB1Id, $contractIds);

        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $this->assertLessThan(
            30_000,
            $elapsedMs,
            sprintf('RbacSpineTest exceeded 30s budget (%.0f ms). Shrink RbacFixture.', $elapsedMs),
        );
    }

    private function step_leasing_agent_signs_at_granted_site(): void
    {
        Sanctum::actingAs($this->fx->ana);

        $response = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->fx->freeUnitA()->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertCreated();
        $contractId = (int) $response->json('data.id');

        $this->assertDatabaseHas('contracts', ['id' => $contractId]);
        $this->assertTrue(Charge::query()->where('contract_id', $contractId)->exists());
        $this->assertTrue(
            UnitOccupancy::query()
                ->where('contract_id', $contractId)
                ->where('unit_id', $this->fx->freeUnitA()->id)
                ->whereNull('ended_on')
                ->exists(),
        );
        $this->assertTrue(
            Contract::query()->findOrFail($contractId)->items()->exists(),
        );
    }

    private function step_denied_signing_writes_nothing(): void
    {
        Sanctum::actingAs($this->fx->ana);

        $beforeContracts = Contract::query()->count();
        $beforeCharges = Charge::query()->count();
        $beforeOccupancies = UnitOccupancy::query()->count();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->fx->freeUnitB()->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('data.permission', Permission::ContractSign->value);

        $this->assertSame($beforeContracts, Contract::query()->count());
        $this->assertSame($beforeCharges, Charge::query()->count());
        $this->assertSame($beforeOccupancies, UnitOccupancy::query()->count());
    }

    private function step_accountant_issues_invoice_cannot_sign(): void
    {
        Sanctum::actingAs($this->fx->carmen);

        // Deny against a free site-B unit (accountant has no contract.sign anywhere).
        $sign = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->fx->freeUnitB()->id,
                'amount' => '100.00',
            ]],
        ]);
        $sign->assertForbidden();
        $sign->assertJsonPath('data.permission', Permission::ContractSign->value);

        $extra = Charge::factory()->create([
            'contract_id' => $this->fx->contractA1Id,
            'invoice_id' => null,
            'charge_type' => ChargeType::Rent,
            'amount' => '25.00',
            'net_amount' => '25.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        $this->postJson('/api/contracts/'.$this->fx->contractA1Id.'/invoices', [
            'charge_ids' => [$extra->id],
        ])->assertSuccessful();

        $carmenAgeing = $this->getJson('/api/reports/ageing?as_of=2026-08-20')->assertOk();
        $carmenTotal = BillingMath::round2(
            (string) collect($carmenAgeing->json('data.meta.totals_by_currency'))
                ->firstWhere('currency', 'EUR')['amount'],
        );

        // Company-wide accountant sees the same Ageing total as the owner.
        Sanctum::actingAs($this->fx->owner);
        $ownerAgeing = $this->getJson('/api/reports/ageing?as_of=2026-08-20')->assertOk();
        $ownerTotal = BillingMath::round2(
            (string) collect($ownerAgeing->json('data.meta.totals_by_currency'))
                ->firstWhere('currency', 'EUR')['amount'],
        );
        $this->assertSame($ownerTotal, $carmenTotal);

        $board = $this->getJson('/api/delinquencies?per_page=100')->assertOk();
        $chip = collect($board->json('meta.overdue_by_currency'))->firstWhere('currency', 'EUR');
        $this->assertNotNull($chip);
        $this->assertNotSame(
            '0.00',
            BillingMath::round2((string) $chip['amount']),
            'Company board overdue chip must be positive',
        );
    }

    private function step_regrant_applies_without_relogin(): void
    {
        Sanctum::actingAs($this->fx->owner);

        $grant = EmployeeRole::query()
            ->where('employee_id', $this->fx->ana->id)
            ->where('role_id', Role::query()->where('key', 'leasing_agent')->value('id'))
            ->firstOrFail();

        $this->deleteJson('/api/employees/'.$this->fx->ana->id.'/roles/'.$grant->id)->assertOk();

        $roleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');
        $this->postJson('/api/employees/'.$this->fx->ana->id.'/roles', [
            'role_id' => $roleId,
            'site_id' => $this->fx->siteB->id,
        ])->assertCreated();

        // loadMissing would otherwise keep the pre-revoke employeeRoles relation.
        $this->fx->ana->unsetRelation('employeeRoles');
        $this->fx->ana->forgetPermissionMap();
        Sanctum::actingAs($this->fx->ana);

        $this->getJson('/api/contracts/'.$this->fx->contractB1Id)->assertOk();
        $this->getJson('/api/contracts/'.$this->fx->contractA1Id)->assertNotFound();

        $list = $this->getJson('/api/contracts?per_page=100')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->fx->contractB1Id, $ids);
        $this->assertNotContains($this->fx->contractA1Id, $ids);
    }

    private function step_last_owner_protected(): void
    {
        Sanctum::actingAs($this->fx->owner);

        $grant = $this->fx->ownerGrant();
        $response = $this->deleteJson('/api/employees/'.$this->fx->owner->id.'/roles/'.$grant->id);
        $response->assertStatus(422);
        $this->assertDatabaseHas('employee_roles', ['id' => $grant->id]);
    }
}
