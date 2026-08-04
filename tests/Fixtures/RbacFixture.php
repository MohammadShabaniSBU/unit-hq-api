<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Role;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Database\Factories\EmployeeFactory;
use Database\Seeders\RbacSystemRoleSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Small purpose-built world for RBAC spine tests. Contracts are signed through
 * POST /api/contracts (real ContractBilling / OccupancyGuard path) — never raw
 * inserts of contracts, occupancies, or charges.
 */
final class RbacFixture
{
    /**
     * @param  list<Unit>  $unitsA
     * @param  list<Unit>  $unitsB
     */
    private function __construct(
        public readonly Employee $owner,
        public readonly Employee $ana,
        public readonly Employee $siteManager,
        public readonly Employee $carmen,
        public readonly Site $siteA,
        public readonly Site $siteB,
        public readonly UnitClass $unitClass,
        public readonly array $unitsA,
        public readonly array $unitsB,
        public readonly Contact $dualSiteContact,
        public readonly int $contractA1Id,
        public readonly int $contractA2Id,
        public readonly int $contractB1Id,
        public readonly int $contractB2Id,
        public readonly Delinquency $delinquencyA,
        public readonly Delinquency $delinquencyB,
        public readonly int $priceIdA,
        public readonly int $priceIdB,
    ) {}

    public static function create(TestCase $test): self
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        RbacSystemRoleSeeder::upsertSystemRoles();

        $owner = Employee::factory()->manager()->create([
            'name' => 'Owner',
            'email' => 'owner-rbac@example.com',
        ]);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $policy = DelinquencyPolicy::factory()->create(['name' => 'RBAC spine']);

        $siteA = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'name' => 'Site A',
            'delinquency_policy_id' => $policy->id,
        ]);
        $siteB = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'name' => 'Site B',
            'delinquency_policy_id' => $policy->id,
        ]);

        $unitClass = UnitClass::factory()->create();
        $priceIdA = self::cataloguePrice($unitClass->id, $siteA->id, $owner->id);
        $priceIdB = self::cataloguePrice($unitClass->id, $siteB->id, $owner->id);
        $unitClass->update(['current_price_id' => $priceIdA]);

        $unitsA = [];
        $unitsB = [];
        for ($i = 0; $i < 3; $i++) {
            $unitsA[] = Unit::factory()->create([
                'site_id' => $siteA->id,
                'unit_class_id' => $unitClass->id,
            ]);
            $unitsB[] = Unit::factory()->create([
                'site_id' => $siteB->id,
                'unit_class_id' => $unitClass->id,
            ]);
        }

        $ana = Employee::factory()->withoutRoleGrant()->create([
            'name' => 'Ana',
            'email' => 'ana-rbac@example.com',
        ]);
        self::grantRole($ana, 'leasing_agent', $siteA);

        $siteManager = Employee::factory()->withoutRoleGrant()->create([
            'name' => 'Site Manager',
            'email' => 'sm-rbac@example.com',
        ]);
        self::grantRole($siteManager, 'site_manager', $siteA);

        $carmen = Employee::factory()->withoutRoleGrant()->create([
            'name' => 'Carmen',
            'email' => 'carmen-rbac@example.com',
        ]);
        EmployeeFactory::grantCompanyRole($carmen, 'accountant');

        $dual = Contact::factory()->create([
            'first_name' => 'Dual',
            'last_name' => 'Site',
        ]);
        $otherA = Contact::factory()->create(['first_name' => 'Other', 'last_name' => 'A']);
        $otherB = Contact::factory()->create(['first_name' => 'Other', 'last_name' => 'B']);

        // Past move-in so first-period charges are overdue by test now (2026-08-15).
        $contractA1Id = self::sign($test, $owner, $dual, $unitsA[0], '2026-06-01');
        $contractA2Id = self::sign($test, $owner, $otherA, $unitsA[1], '2026-06-01');
        $contractB1Id = self::sign($test, $owner, $dual, $unitsB[0], '2026-06-01');
        $contractB2Id = self::sign($test, $owner, $otherB, $unitsB[1], '2026-06-01');

        $delinquencyA = DelinquencyLifecycle::openOrFail(
            Contract::query()->with(['unitItem.item.site', 'charges.allocations'])->findOrFail($contractA2Id),
        );
        $delinquencyB = DelinquencyLifecycle::openOrFail(
            Contract::query()->with(['unitItem.item.site', 'charges.allocations'])->findOrFail($contractB2Id),
        );

        return new self(
            owner: $owner,
            ana: $ana,
            siteManager: $siteManager,
            carmen: $carmen,
            siteA: $siteA,
            siteB: $siteB,
            unitClass: $unitClass,
            unitsA: $unitsA,
            unitsB: $unitsB,
            dualSiteContact: $dual,
            contractA1Id: $contractA1Id,
            contractA2Id: $contractA2Id,
            contractB1Id: $contractB1Id,
            contractB2Id: $contractB2Id,
            delinquencyA: $delinquencyA,
            delinquencyB: $delinquencyB,
            priceIdA: $priceIdA,
            priceIdB: $priceIdB,
        );
    }

    public function freeUnitA(): Unit
    {
        return $this->unitsA[2];
    }

    public function freeUnitB(): Unit
    {
        return $this->unitsB[2];
    }

    public function anaGrant(): EmployeeRole
    {
        return EmployeeRole::query()
            ->where('employee_id', $this->ana->id)
            ->where('role_id', Role::query()->where('key', 'leasing_agent')->value('id'))
            ->where('site_id', $this->siteA->id)
            ->firstOrFail();
    }

    public function ownerGrant(): EmployeeRole
    {
        return EmployeeRole::query()
            ->where('employee_id', $this->owner->id)
            ->where('role_id', Role::query()->where('key', 'owner')->value('id'))
            ->whereNull('site_id')
            ->firstOrFail();
    }

    private static function grantRole(Employee $employee, string $roleKey, Site $site): EmployeeRole
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        return EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => $site->id,
        ]);
    }

    private static function cataloguePrice(int $unitClassId, int $siteId, int $createdBy): int
    {
        $rate = UnitClassRate::query()->firstOrCreate([
            'unit_class_id' => $unitClassId,
            'site_id' => $siteId,
        ]);

        $price = Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $rate->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => '100.00',
            'currency' => 'EUR',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_by' => $createdBy,
        ]);

        return (int) $price->id;
    }

    private static function sign(
        TestCase $test,
        Employee $as,
        Contact $contact,
        Unit $unit,
        string $moveIn,
    ): int {
        Sanctum::actingAs($as);

        $response = $test->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}
