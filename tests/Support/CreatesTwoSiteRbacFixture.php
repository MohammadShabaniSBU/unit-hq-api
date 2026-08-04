<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Country;
use App\Models\LegalEntity;
use App\Models\Role;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Database\Seeders\RbacSystemRoleSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Shared two-site RBAC shape for S17-04 visibility tests: a company-wide
 * owner, a leasing_agent scoped to site A only, and one unit per site on a
 * shared catalogue-priced unit class. Mirrors LeasingAgentScopeTest's setup
 * so every visibility test starts from the same, well-understood fixture.
 */
trait CreatesTwoSiteRbacFixture
{
    use CreatesCataloguePrices;

    protected Employee $owner;

    protected Employee $agent;

    protected Site $siteA;

    protected Site $siteB;

    protected UnitClass $unitClass;

    protected Unit $unitA;

    protected Unit $unitB;

    /** Catalogue price id backing $unitClass at $siteA. */
    protected int $priceIdA;

    /** Catalogue price id backing $unitClass at $siteB. */
    protected int $priceIdB;

    protected function setUpTwoSiteRbacFixture(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $this->owner = Employee::factory()->manager()->create();

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();

        $this->siteA = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'name' => 'Site A',
        ]);
        $this->siteB = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'name' => 'Site B',
        ]);

        $this->unitClass = UnitClass::factory()->create();
        [, $priceA] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->siteA->id,
            $this->owner->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        [, $priceB] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->siteB->id,
            $this->owner->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $this->priceIdA = (int) $priceA->id;
        $this->priceIdB = (int) $priceB->id;
        $this->unitClass->update(['current_price_id' => $priceA->id]);

        $this->unitA = Unit::factory()->create([
            'site_id' => $this->siteA->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $this->unitB = Unit::factory()->create([
            'site_id' => $this->siteB->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $this->agent = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($this->agent, 'leasing_agent', $this->siteA);
    }

    /**
     * Grant a system role to $employee, company-wide when $site is null.
     */
    protected function grantRole(Employee $employee, string $roleKey, ?Site $site): EmployeeRole
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        return EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => $site?->id,
        ]);
    }

    /**
     * Sign an active contract at $unit acting as the company-wide owner, so
     * fixtures can be planted at either site regardless of which employee a
     * given test is exercising. Restores no actor afterwards — callers must
     * re-authenticate as the actor under test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: int, 1: Contact} [contract id, contact]
     */
    protected function signContractAsOwner(Unit $unit, array $overrides = []): array
    {
        $contact = Contact::factory()->create();

        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/contracts', array_merge([
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '100.00',
            ]],
        ], $overrides));

        $response->assertCreated();

        return [(int) $response->json('data.id'), $contact];
    }
}
