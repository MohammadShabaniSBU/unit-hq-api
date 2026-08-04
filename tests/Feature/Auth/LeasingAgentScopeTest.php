<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LegalEntity;
use App\Models\Role;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class LeasingAgentScopeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $owner;

    private Employee $agent;

    private Site $siteA;

    private Site $siteB;

    private Unit $unitA;

    private Unit $unitB;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();

        $this->owner = Employee::factory()->manager()->create();

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();

        $this->siteA = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
        ]);
        $this->siteB = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
        ]);

        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->siteA->id,
            $this->owner->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->siteB->id,
            $this->owner->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);

        $this->unitA = Unit::factory()->create([
            'site_id' => $this->siteA->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $this->unitB = Unit::factory()->create([
            'site_id' => $this->siteB->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $this->agent = Employee::factory()->withoutRoleGrant()->create();
        $leasingAgent = Role::query()->where('key', 'leasing_agent')->firstOrFail();
        EmployeeRole::query()->create([
            'employee_id' => $this->agent->id,
            'role_id' => $leasingAgent->id,
            'site_id' => $this->siteA->id,
        ]);
    }

    #[Test]
    public function cannot_sign_contract_at_other_site(): void
    {
        Sanctum::actingAs($this->agent);

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
                'item_id' => $this->unitB->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'errors.forbidden');
        $response->assertJsonPath('data.permission', Permission::ContractSign->value);

        $this->assertSame($beforeContracts, Contract::query()->count());
        $this->assertSame($beforeCharges, Charge::query()->count());
        $this->assertSame($beforeOccupancies, UnitOccupancy::query()->count());
    }

    #[Test]
    public function can_sign_contract_at_granted_site(): void
    {
        Sanctum::actingAs($this->agent);

        $response = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '50.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unitA->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertSuccessful();
        $contractId = (int) $response->json('data.id');

        $this->assertDatabaseHas('contracts', ['id' => $contractId]);
        $this->assertTrue(
            Charge::query()->where('contract_id', $contractId)->exists(),
        );
        $this->assertTrue(
            UnitOccupancy::query()
                ->where('contract_id', $contractId)
                ->where('unit_id', $this->unitA->id)
                ->whereNull('ended_on')
                ->exists(),
        );
    }
}
