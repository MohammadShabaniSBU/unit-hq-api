<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

/**
 * S17-04 — reports namespace scoped: a site-scoped employee's Ageing report
 * must reconcile to their own delinquency board chip (S16 property preserved
 * under scoping), and money reports must never leak another site's totals.
 */
class ReportScopeTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'Europe/Madrid'));

        $this->setUpTwoSiteRbacFixture();

        $policy = DelinquencyPolicy::factory()->create(['name' => 'scope-policy']);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 3,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);
        $this->siteA->update(['delinquency_policy_id' => $policy->id]);
        $this->siteB->update(['delinquency_policy_id' => $policy->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function ageing_reconciles_to_scoped_board(): void
    {
        $unitA2 = Unit::factory()->create(['site_id' => $this->siteA->id, 'unit_class_id' => $this->unitClass->id]);

        $this->seedOpenCase($this->unitA, '2026-08-05', '50.00', $this->priceIdA); // 15 days overdue
        $this->seedOpenCase($unitA2, '2026-07-20', '30.00', $this->priceIdA); // 31 days overdue
        $this->seedOpenCase($this->unitB, '2026-08-01', '75.00', $this->priceIdB); // out of scope for the agent

        // leasing_agent has neither report.view nor report.financial.view;
        // add a site-A-scoped read_only grant so the agent can reach the
        // report while staying scoped to site A only.
        $this->grantRole($this->agent, 'read_only', $this->siteA);
        $this->agent->forgetPermissionMap();
        Sanctum::actingAs($this->agent);

        $api = $this->getJson('/api/reports/ageing?as_of=2026-08-20')->assertOk();
        $reportTotal = BillingMath::round2(
            (string) collect($api->json('data.meta.totals_by_currency'))
                ->firstWhere('currency', 'EUR')['amount'],
        );

        $board = $this->getJson('/api/delinquencies?site_id='.$this->siteA->id.'&per_page=100')->assertOk();
        $chip = collect($board->json('meta.overdue_by_currency'))->firstWhere('currency', 'EUR');

        $this->assertNotNull($chip);
        $this->assertSame('80.00', BillingMath::round2((string) $chip['amount'])); // 50.00 + 30.00, site A only
        $this->assertSame($chip['amount'], $reportTotal);

        // Site B's case never appears in either surface for the scoped agent.
        $siteNames = collect($api->json('data.rows'))->pluck('site')->unique()->values()->all();
        $this->assertNotContains($this->siteB->name, $siteNames);
        $this->assertContains($this->siteA->name, $siteNames);
    }

    #[Test]
    public function rent_roll_scoped_totals(): void
    {
        [$contractAId] = $this->signContractAsOwner($this->unitA, ['deposit_amount' => '50.00']);
        [$contractBId] = $this->signContractAsOwner($this->unitB, ['deposit_amount' => '75.00']);

        $this->grantRole($this->agent, 'read_only', $this->siteA);
        $this->agent->forgetPermissionMap();
        Sanctum::actingAs($this->agent);

        $api = $this->getJson('/api/reports/rent-roll?as_of=2026-08-20')->assertOk();

        $contractIds = collect($api->json('data.rows'))->pluck('contract_id')->all();
        $this->assertContains($contractAId, $contractIds);
        $this->assertNotContains($contractBId, $contractIds);

        $this->assertSame(1, $api->json('data.meta.footer.units'));
        $this->assertSame('100.00', $api->json('data.meta.footer.monthly_rent'));
        $this->assertSame('50.00', $api->json('data.meta.footer.deposits'));
    }

    private function seedOpenCase(Unit $unit, string $dueDate, string $amount, int $priceId): Delinquency
    {
        $contract = Contract::factory()->create([
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $priceId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        \App\Models\UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => $dueDate,
        ]);

        $contract = $contract->fresh(['unitItem.item.site', 'charges.allocations']) ?? $contract;

        return DelinquencyLifecycle::openOrFail($contract);
    }
}
