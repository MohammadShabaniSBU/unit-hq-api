<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

/**
 * S17-04 — list visibility & site scoping (contract-centred; Contact/Deal
 * D-RBAC-1 detail lives in ContactVisibilityTest).
 */
class VisibilityTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
    }

    #[Test]
    public function company_grant_applies_no_filter(): void
    {
        [$contractAId] = $this->signContractAsOwner($this->unitA);
        [$contractBId] = $this->signContractAsOwner($this->unitB);

        Sanctum::actingAs($this->owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/contracts')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($contractAId, $ids);
        $this->assertContains($contractBId, $ids);
        $this->assertSame(2, $response->json('meta.total'));

        // Fast path: a company-wide grant must not add the unit_occupancies
        // whereExists that SitePath::constrain() introduces for site scoping.
        $mainQuery = collect($log)->first(
            fn (array $q): bool => str_contains($q['query'], 'from "contracts"') || str_contains($q['query'], 'from `contracts`'),
        );
        $this->assertNotNull($mainQuery, 'Expected to find the contracts list query in the log.');
        $this->assertStringNotContainsString('unit_occupancies', $mainQuery['query']);
    }

    #[Test]
    public function site_grant_filters_contracts(): void
    {
        [$contractAId] = $this->signContractAsOwner($this->unitA);
        [$contractBId] = $this->signContractAsOwner($this->unitB);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson('/api/contracts')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($contractAId, $ids);
        $this->assertNotContains($contractBId, $ids);
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function no_grant_returns_empty_not_error(): void
    {
        $this->signContractAsOwner($this->unitA);

        $lonely = Employee::factory()->withoutRoleGrant()->create();

        // The primitive itself: holding a permission nowhere empties the
        // set via whereRaw('1 = 0'), it never throws.
        $this->assertSame([], $lonely->siteIdsFor(Permission::ContractView));
        $this->assertSame(
            0,
            Contract::query()->visibleTo($lonely, Permission::ContractView)->count(),
        );

        // Reachability is task 03's concern: an employee who does not hold
        // the permission at all never reaches the scope — they 403 first.
        Sanctum::actingAs($lonely);
        $this->getJson('/api/contracts')->assertForbidden();

        // Simulate task 03 already having let the request through (permission
        // held, but at no site) to prove the endpoint's own answer is an
        // honest empty list, per the acceptance table ("1=0, 200 with empty
        // data") rather than an error.
        Gate::before(fn () => true);
        $response = $this->getJson('/api/contracts');
        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonCount(0, 'data');
    }

    #[Test]
    public function board_counts_match_scoped_list(): void
    {
        $unitA2 = \App\Models\Unit::factory()->create([
            'site_id' => $this->siteA->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $this->signContractAsOwner($this->unitA);
        $this->signContractAsOwner($unitA2);
        $this->signContractAsOwner($this->unitB);

        $contactA = Contact::factory()->create();
        Deal::factory()->create(['contact_id' => $contactA->id, 'site_id' => $this->siteA->id]);
        $contactB = Contact::factory()->create();
        Deal::factory()->create(['contact_id' => $contactB->id, 'site_id' => $this->siteB->id]);

        Sanctum::actingAs($this->agent);

        $cases = [
            'contract' => ['board' => '/api/contracts/board', 'list' => '/api/contracts'],
            'deal' => ['board' => '/api/deals/board', 'list' => '/api/deals'],
            'contact' => ['board' => '/api/contacts/board', 'list' => '/api/contacts'],
        ];

        foreach ($cases as $name => $urls) {
            $board = $this->getJson($urls['board'])->assertOk();
            $boardTotal = collect($board->json('data.columns'))->sum('total');

            $list = $this->getJson($urls['list'].'?per_page=100')->assertOk();
            $listTotal = $list->json('meta.total');

            $this->assertSame($listTotal, $boardTotal, "Board/list total mismatch for [{$name}]");
        }
    }

    #[Test]
    public function sites_options_returns_only_granted(): void
    {
        Sanctum::actingAs($this->agent);
        $agentOptions = $this->getJson('/api/sites/options')->assertOk();
        $agentIds = collect($agentOptions->json('data'))->pluck('value')->all();
        $this->assertSame([$this->siteA->id], $agentIds);

        Sanctum::actingAs($this->owner);
        $ownerOptions = $this->getJson('/api/sites/options')->assertOk();
        $ownerIds = collect($ownerOptions->json('data'))->pluck('value')->all();
        $this->assertContains($this->siteA->id, $ownerIds);
        $this->assertContains($this->siteB->id, $ownerIds);
    }

    #[Test]
    public function search_tree_cannot_widen_scope(): void
    {
        [$contractAId] = $this->signContractAsOwner($this->unitA);
        [$contractBId] = $this->signContractAsOwner($this->unitB);

        Sanctum::actingAs($this->agent);

        // A deliberately maximal filter tree — matches every contract in the
        // database by native, whitelisted fields alone (no site_id exists in
        // FilterableFields to exploit). Visibility is applied to the base
        // builder before FilterBuilder runs, so this can never surface B.
        $response = $this->postJson('/api/contracts/search', [
            'filter' => [
                'op' => 'or',
                'conditions' => [
                    ['field' => 'contact_id', 'op' => 'gt', 'value' => 0],
                    ['field' => 'created_at', 'op' => 'after', 'value' => '2000-01-01'],
                ],
            ],
        ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($contractAId, $ids);
        $this->assertNotContains($contractBId, $ids);
    }

    #[Test]
    public function out_of_scope_record_is_404_on_read_and_action(): void
    {
        [$contractBId] = $this->signContractAsOwner($this->unitB);

        Sanctum::actingAs($this->agent);
        $this->getJson("/api/contracts/{$contractBId}")->assertNotFound();

        // leasing_agent has no ContractVacate grant at all, so proving the
        // read/action pair is consistent needs a role that can reach vacate.
        // site_manager holds it, scoped to site A only.
        $manager = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($manager, 'site_manager', $this->siteA);
        Sanctum::actingAs($manager);

        $this->getJson("/api/contracts/{$contractBId}")->assertNotFound();
        $this->postJson("/api/contracts/{$contractBId}/vacate", [
            'move_out_on' => '2026-08-01',
            'deposit' => ['outcome' => 'released'],
        ])->assertNotFound();
    }
}
