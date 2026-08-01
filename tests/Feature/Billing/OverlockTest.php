<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\HoldType;
use App\Enums\MoveOutSettlement;
use App\Enums\UnitState;
use App\Http\Resources\ContractResource;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Delinquency\Overlock;
use App\Support\Occupancy\Availability;
use App\Support\Occupancy\HoldGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class OverlockTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private DelinquencyPolicy $policy;

    private UnitClass $unitClass;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create([
            'name' => 'overlock-policy',
            'auto_release_overlock' => true,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 1,
        ]);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $this->policy->id,
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = $price->id;
        $this->unitClass->update(['current_price_id' => $price->id]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '0.00',
            moveOutSettlement: MoveOutSettlement::None->value,
            turnoverHoldDays: 0,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_place_all_units_idempotent(): void
    {
        $unitA = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $unitB = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        [$contract, $case] = $this->openCaseWithUnits([$unitA, $unitB]);

        $first = Overlock::place($case);
        $this->assertIsArray($first);
        $this->assertCount(2, $first);
        $ids = collect($first)->pluck('id')->sort()->values()->all();

        $second = Overlock::place($case);
        $this->assertIsArray($second);
        $this->assertSame(
            $ids,
            collect($second)->pluck('id')->sort()->values()->all()
        );

        $this->assertSame(
            2,
            UnitHold::query()
                ->where('hold_type', HoldType::Overlock)
                ->whereNull('released_at')
                ->where('reason', Overlock::reasonFor($case))
                ->count()
        );

        // Partial unique (Postgres): cannot insert a second live overlock on the same unit.
        if (DB::getDriverName() === 'pgsql') {
            $this->expectException(\Illuminate\Database\QueryException::class);
            UnitHold::query()->create([
                'unit_id' => $unitA->id,
                'hold_type' => HoldType::Overlock,
                'starts_on' => '2026-08-15',
                'ends_on' => null,
                'reason' => 'duplicate-attempt',
                'created_by' => $this->employee->id,
            ]);
        }
    }

    public function test_auto_release_on_cure_flag_matrix(): void
    {
        // Flag true — cure path auto-releases + appends release step
        $this->policy->forceFill(['auto_release_overlock' => true])->save();
        $unitTrue = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        [, $caseTrue] = $this->openCaseWithUnits([$unitTrue]);
        Overlock::place($caseTrue);

        DelinquencyLifecycle::cure($caseTrue, DelinquencyCureTrigger::Payment);
        Overlock::release($caseTrue, 'cure');

        $this->assertSame(
            0,
            UnitHold::query()
                ->where('reason', Overlock::reasonFor($caseTrue))
                ->whereNull('released_at')
                ->count()
        );
        $releaseStep = $caseTrue->steps()
            ->where('action', DelinquencyStepAction::ReleaseOverlock)
            ->first();
        $this->assertNotNull($releaseStep);
        $this->assertSame(DelinquencyStepTrigger::Cure, $releaseStep->trigger);

        // Flag false — holds stay; pending_release; manual release clears
        $this->policy->forceFill(['auto_release_overlock' => false])->save();
        $unitFalse = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        [$contractFalse, $caseFalse] = $this->openCaseWithUnits([$unitFalse]);
        Overlock::place($caseFalse);

        DelinquencyLifecycle::cure($caseFalse, DelinquencyCureTrigger::Payment);
        // Engine skips release when flag false — leave holds live.
        $this->assertSame(
            1,
            UnitHold::query()
                ->where('reason', Overlock::reasonFor($caseFalse))
                ->whereNull('released_at')
                ->count()
        );

        $payload = ContractResource::make($contractFalse->fresh(['delinquencies']))
            ->toArray(Request::create('/'));
        $this->assertTrue($payload['overlock']['active']);
        $this->assertTrue($payload['overlock']['pending_release']);
        $this->assertSame($caseFalse->id, $payload['overlock']['delinquency_id']);

        Overlock::release($caseFalse, 'manual', $this->employee);
        $this->assertSame(
            0,
            UnitHold::query()
                ->where('reason', Overlock::reasonFor($caseFalse))
                ->whereNull('released_at')
                ->count()
        );
        $manualStep = $caseFalse->steps()
            ->where('action', DelinquencyStepAction::ReleaseOverlock)
            ->where('trigger', DelinquencyStepTrigger::Manual)
            ->first();
        $this->assertNotNull($manualStep);
        $this->assertSame($this->employee->id, $manualStep->created_by);
    }

    public function test_vacate_guard(): void
    {
        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '0.00',
            moveOutSettlement: MoveOutSettlement::None->value,
            turnoverHoldDays: 0,
        ));

        // Flag false + live overlock → 422
        $this->policy->forceFill(['auto_release_overlock' => false])->save();
        $unitBlocked = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        [$contractBlocked, $caseBlocked] = $this->openCaseWithUnits([$unitBlocked]);
        Overlock::place($caseBlocked);

        $this->postJson("/api/contracts/{$contractBlocked->id}/vacate", [
            'move_out_on' => '2026-08-15',
            'deposit' => ['outcome' => 'released'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contract']);

        $this->assertNull($contractBlocked->fresh()->move_out_on);
        $this->assertTrue(
            UnitHold::query()
                ->where('reason', Overlock::reasonFor($caseBlocked))
                ->whereNull('released_at')
                ->exists()
        );

        // Flag true → vacate succeeds and releases before/at close
        $this->policy->forceFill(['auto_release_overlock' => true])->save();
        $unitOk = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        [$contractOk, $caseOk] = $this->openCaseWithUnits([$unitOk]);
        Overlock::place($caseOk);

        $this->postJson("/api/contracts/{$contractOk->id}/vacate", [
            'move_out_on' => '2026-08-15',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $this->assertSame('ended', $contractOk->fresh()->status->value);
        $this->assertSame(
            0,
            UnitHold::query()
                ->where('reason', Overlock::reasonFor($caseOk))
                ->whereNull('released_at')
                ->count()
        );
        $this->assertTrue(
            $caseOk->steps()
                ->where('action', DelinquencyStepAction::ReleaseOverlock)
                ->exists()
        );
    }

    public function test_holds_api_still_rejects(): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $this->postJson("/api/units/{$unit->id}/holds", [
            'hold_type' => 'overlock',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hold_type']);

        [, $case] = $this->openCaseWithUnits([$unit]);
        $hold = Overlock::place($case);
        $this->assertInstanceOf(UnitHold::class, $hold);

        $this->deleteJson("/api/units/{$unit->id}/holds/{$hold->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hold']);

        $this->assertNull($hold->fresh()->released_at);
    }

    public function test_availability_never_blocked(): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        [, $case] = $this->openCaseWithUnits([$unit]);
        Overlock::place($case);

        $on = CarbonImmutable::parse('2026-08-15');

        // Occupied + overlocked → still Occupied (not a separate state); unavailable due to occupancy.
        $this->assertSame(UnitState::Occupied, Availability::stateOn($unit->id, $on));
        $this->assertFalse(Availability::isAvailable($unit->id, $on));

        // Overlock alone on a vacant unit does not block.
        $vacant = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $orphanCase = Delinquency::query()->create([
            'contract_id' => Contract::factory()->create([
                'contact_id' => Contact::factory()->create()->id,
                'currency' => 'EUR',
                'status' => ContractStatus::Active,
            ])->id,
            'delinquency_policy_id' => $this->policy->id,
            'anchor_due_date' => '2026-08-01',
            'opened_on' => '2026-08-10',
        ]);
        // Direct hold via place needs occupancy/unit item — create hold with case reason.
        UnitHold::query()->create([
            'unit_id' => $vacant->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => '2026-08-10',
            'ends_on' => null,
            'reason' => Overlock::reasonFor($orphanCase),
            'created_by' => $this->employee->id,
        ]);

        $this->assertTrue(Availability::isAvailable($vacant->id, $on));
        $this->assertSame(UnitState::Available, Availability::stateOn($vacant->id, $on));

        HoldGuard::assertUnheld($vacant->id, $on, null);

        Availability::hydrateState(collect([$unit]));
        $this->assertNotNull($unit->getRelation('liveOverlock'));
        $this->assertSame(UnitState::Occupied->value, $unit->derived_state);
    }

    /**
     * @param  list<Unit>  $units
     * @return array{0: Contract, 1: Delinquency}
     */
    private function openCaseWithUnits(array $units): array
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
            'deposit_amount' => '0.00',
        ]);

        foreach ($units as $unit) {
            $item = ContractItem::query()->create([
                'contract_id' => $contract->id,
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'price_id' => $this->priceId,
                'effective_from' => '2026-06-01',
                'effective_to' => null,
            ]);

            UnitOccupancy::query()->create([
                'unit_id' => $unit->id,
                'contract_id' => $contract->id,
                'contract_item_id' => $item->id,
                'started_on' => '2026-06-01',
                'ended_on' => null,
                'created_by' => $this->employee->id,
            ]);
        }

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        $contract->forceFill([
            'billed_through' => '2026-08-01',
            'deposit_amount' => '0.00',
        ])->save();

        $case = Delinquency::query()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $this->policy->id,
            'anchor_due_date' => '2026-08-01',
            'opened_on' => '2026-08-10',
        ]);

        return [$contract->fresh() ?? $contract, $case];
    }
}
