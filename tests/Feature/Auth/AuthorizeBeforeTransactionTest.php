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
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AuthorizeBeforeTransactionTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $owner;

    private Employee $agent;

    private Site $siteA;

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
        $siteB = Site::factory()->create([
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
            $siteB->id,
            $this->owner->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);

        $this->unitA = Unit::factory()->create([
            'site_id' => $this->siteA->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $this->unitB = Unit::factory()->create([
            'site_id' => $siteB->id,
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
    public function denied_request_writes_nothing(): void
    {
        // Existing contract at site B (owner-signed) — agent must not vacate it.
        Sanctum::actingAs($this->owner);
        $signed = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-06-01',
            'move_in_date' => '2026-06-01',
            'deposit_amount' => '50.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unitB->id,
                'amount' => '100.00',
            ]],
        ])->assertCreated();

        $contractId = (int) $signed->json('data.id');
        $contract = Contract::query()->findOrFail($contractId);
        $statusBefore = (string) ($contract->status->value ?? $contract->status);
        $moveOutBefore = $contract->move_out_on;
        $beforeContracts = Contract::query()->count();
        $beforeCharges = Charge::query()->count();
        $beforeOccupancies = UnitOccupancy::query()->count();
        $chargeFingerprint = Charge::query()
            ->where('contract_id', $contractId)
            ->orderBy('id')
            ->get(['id', 'amount', 'invoice_id', 'reversal_of_charge_id'])
            ->toArray();

        Sanctum::actingAs($this->agent);

        // Out-of-scope contract: binding 404s before the vacate policy can 403
        // (enumeration defence — S17-04).
        $vacate = $this->postJson("/api/contracts/{$contractId}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ]);
        $vacate->assertNotFound();

        $sign = $this->postJson('/api/contracts', [
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
        $sign->assertForbidden();

        $contract->refresh();
        $this->assertSame($beforeContracts, Contract::query()->count());
        $this->assertSame($beforeCharges, Charge::query()->count());
        $this->assertSame($beforeOccupancies, UnitOccupancy::query()->count());
        $this->assertSame($statusBefore, (string) ($contract->status->value ?? $contract->status));
        $this->assertEquals($moveOutBefore, $contract->move_out_on);
        $this->assertSame(
            $chargeFingerprint,
            Charge::query()
                ->where('contract_id', $contractId)
                ->orderBy('id')
                ->get(['id', 'amount', 'invoice_id', 'reversal_of_charge_id'])
                ->toArray(),
        );
    }
}
