<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingRunEngine;
use App\Support\Contracts\ActivatePendingContracts;
use App\Support\Delinquency\DelinquencyEngine;
use App\Support\Occupancy\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class EligibilityAuditTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_five_systems_exclude_awaiting(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 10:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-01',
            'move_in_date' => '2026-07-01',
            'deposit_amount' => '0.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '100.00',
            ]],
        ])->assertCreated();

        $contractId = (int) $response->json('data.id');
        $contract = Contract::query()->findOrFail($contractId);
        $this->assertSame(ContractStatus::AwaitingSignature, $contract->status);

        // 1) Billing run eligibility — awaiting + null billed_through never enters.
        $preview = (new BillingRunEngine)->run(
            BillingRunTrigger::Manual,
            $contractId,
            dryRun: true,
        );
        $this->assertSame([], $preview);

        // 2) Delinquency scan — status allow-list excludes awaiting.
        $delinquency = (new DelinquencyEngine)->run($contractId);
        $this->assertSame(0, $delinquency['evaluated'] ?? 0);

        // 3) Activation job — only Pending is selected.
        $activation = (new ActivatePendingContracts)->run();
        $contract->refresh();
        $this->assertSame(ContractStatus::AwaitingSignature, $contract->status);
        $this->assertSame(0, $activation['activated']);

        // 4) Occupancy-based availability — blocked via hold, not occupancy.
        $this->assertSame(0, UnitOccupancy::query()->where('contract_id', $contractId)->count());
        $this->assertTrue(
            UnitHold::query()
                ->where('contract_id', $contractId)
                ->where('hold_type', HoldType::ContractSignature->value)
                ->whereNull('released_at')
                ->exists()
        );
        $today = CarbonImmutable::parse('2026-07-10');
        $this->assertFalse(Availability::isAvailable($unit->id, $today));

        // 5) Holds API rejects the system type.
        $this->postJson("/api/units/{$unit->id}/holds", [
            'hold_type' => HoldType::ContractSignature->value,
            'reason' => 'should fail',
        ])->assertStatus(422)->assertJsonValidationErrors(['hold_type']);

        $holdId = UnitHold::query()->where('contract_id', $contractId)->value('id');
        $this->assertNotNull($holdId);
        $this->deleteJson("/api/units/{$unit->id}/holds/{$holdId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hold']);

        CarbonImmutable::setTestNow();
    }
}
