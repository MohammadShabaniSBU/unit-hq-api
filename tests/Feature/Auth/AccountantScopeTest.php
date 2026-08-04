<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Database\Factories\EmployeeFactory;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AccountantScopeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $owner;

    private Employee $accountant;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();

        $this->owner = Employee::factory()->manager()->create();

        $this->accountant = Employee::factory()->withoutRoleGrant()->create();
        EmployeeFactory::grantCompanyRole($this->accountant, 'accountant');

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->owner->id,
            ['amount' => '80.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    #[Test]
    public function can_issue_invoice_cannot_sign_contract(): void
    {
        Sanctum::actingAs($this->accountant);

        $sign = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '80.00',
            ]],
        ]);

        $sign->assertForbidden();
        $sign->assertJsonPath('message', 'errors.forbidden');
        $sign->assertJsonPath('data.permission', 'contract.sign');

        // Owner signs so we have a contract + an uninvoiced charge the accountant can issue.
        Sanctum::actingAs($this->owner);
        $created = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '80.00',
            ]],
        ])->assertCreated();

        $contractId = (int) $created->json('data.id');

        $extraCharge = Charge::factory()->create([
            'contract_id' => $contractId,
            'invoice_id' => null,
            'charge_type' => ChargeType::Rent,
            'amount' => '25.00',
            'net_amount' => '25.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        Sanctum::actingAs($this->accountant);

        $issue = $this->postJson("/api/contracts/{$contractId}/invoices", [
            'charge_ids' => [$extraCharge->id],
        ]);

        $issue->assertSuccessful();
        $invoiceId = (int) $issue->json('data.id');
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'contract_id' => $contractId,
        ]);
        $this->assertSame($invoiceId, (int) Charge::query()->findOrFail($extraCharge->id)->invoice_id);
    }
}
