<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Contracts\ContractSigning;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AwaitingTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 10:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $this->contact = Contact::factory()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_deferred_creation_shape(): void
    {
        $response = $this->postJson('/api/contracts', $this->remotePayload());

        $response->assertCreated();
        $contractId = (int) $response->json('data.id');

        $this->assertSame(ContractStatus::AwaitingSignature->value, $response->json('data.status'));
        $this->assertNull($response->json('data.signed_at'));
        $this->assertNull($response->json('data.billed_through'));

        $hold = UnitHold::query()
            ->where('contract_id', $contractId)
            ->where('hold_type', HoldType::ContractSignature->value)
            ->whereNull('released_at')
            ->first();
        $this->assertNotNull($hold);
        $this->assertSame($this->unit->id, $hold->unit_id);
        $this->assertNull($hold->ends_on);

        $this->assertSame(0, Charge::query()->where('contract_id', $contractId)->count());
        $this->assertSame(0, Invoice::query()->where('contract_id', $contractId)->count());
        $this->assertSame(0, UnitOccupancy::query()->where('contract_id', $contractId)->count());

        $secondUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
        ]);

        // Same unit: double-booking blocked by the signature hold.
        $blocked = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'signature_mode' => 'immediate',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '100.00',
            ]],
        ]);
        $blocked->assertStatus(422)->assertJsonValidationErrors(['unit_id']);

        // Different unit still available.
        $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $secondUnit->id,
                'amount' => '100.00',
            ]],
        ])->assertCreated();
    }

    public function test_completion_atomic_swap(): void
    {
        $create = $this->postJson('/api/contracts', $this->remotePayload())->assertCreated();
        $contract = Contract::query()->findOrFail($create->json('data.id'));

        try {
            DB::transaction(function () use ($contract): void {
                ContractSigning::complete($contract, null, $this->employee->id);
                throw new RuntimeException('force rollback');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('force rollback', $e->getMessage());
        }

        $contract->refresh();
        $this->assertSame(ContractStatus::AwaitingSignature, $contract->status);
        $this->assertNull($contract->signed_at);
        $this->assertNull($contract->billed_through);
        $this->assertSame(0, Charge::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, Invoice::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, UnitOccupancy::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, UnitHold::query()
            ->where('contract_id', $contract->id)
            ->whereNull('released_at')
            ->count());

        DB::transaction(function () use ($contract): void {
            ContractSigning::complete($contract->fresh(), null, $this->employee->id);
        });

        $contract->refresh();
        $this->assertContains($contract->status, [ContractStatus::Pending, ContractStatus::Active]);
        $this->assertNotNull($contract->signed_at);
        $this->assertNotNull($contract->billed_through);
        $this->assertGreaterThan(0, Charge::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, Invoice::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, UnitOccupancy::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, UnitHold::query()
            ->where('contract_id', $contract->id)
            ->whereNull('released_at')
            ->count());
    }

    public function test_cancel_clean(): void
    {
        $create = $this->postJson('/api/contracts', $this->remotePayload())->assertCreated();
        $contractId = (int) $create->json('data.id');

        $this->postJson("/api/contracts/{$contractId}/cancel")
            ->assertOk();

        $contract = Contract::query()->findOrFail($contractId);
        $this->assertSame(ContractStatus::Cancelled, $contract->status);
        $this->assertSame(ContractEndedReason::Cancelled, $contract->ended_reason);
        $this->assertNull($contract->signed_at);
        $this->assertSame(0, Charge::query()->where('contract_id', $contractId)->count());
        $this->assertSame(0, Invoice::query()->where('contract_id', $contractId)->count());
        $this->assertSame(0, UnitOccupancy::query()->where('contract_id', $contractId)->count());
        $this->assertSame(0, UnitHold::query()
            ->where('contract_id', $contractId)
            ->whereNull('released_at')
            ->count());
        $this->assertSame(1, UnitHold::query()
            ->where('contract_id', $contractId)
            ->whereNotNull('released_at')
            ->count());
    }

    /** @return array<string, mixed> */
    private function remotePayload(): array
    {
        return [
            'contact_id' => $this->contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '100.00',
            ]],
        ];
    }
}
