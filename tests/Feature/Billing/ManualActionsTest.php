<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractNoticeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\HoldType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ManualActionsTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private Delinquency $case;

    private Contract $contract;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create([
            'name' => 'manual-policy',
            'auto_release_overlock' => true,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '15.00'],
            'sort' => 1,
        ]);

        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $policy->id,
        ]);

        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = $price->id;
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $this->contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);

        $item = ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $this->contract->id,
            'contract_item_id' => $item->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
            'created_by' => $this->employee->id,
        ]);

        Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        $this->contract = $this->contract->fresh(['unitItem.item.site', 'charges.allocations']) ?? $this->contract;
        $this->case = DelinquencyLifecycle::openOrFail($this->contract);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_action_audits_and_timelines(): void
    {
        $id = $this->case->id;

        // Assess fee
        $fee = $this->postJson("/api/delinquencies/{$id}/assess-fee", [
            'amount' => '12.50',
            'reason' => 'manual late fee',
        ]);
        $fee->assertOk();
        $this->assertTrue(collect($fee->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'assess_late_fee'
                && $s['trigger'] === 'manual'
                && ($s['created_by']['id'] ?? null) === $this->employee->id
                && ($s['charge']['amount'] ?? null) === '12.50'
        ));

        // Place overlock
        $lock = $this->postJson("/api/delinquencies/{$id}/overlock", [
            'unit_id' => $this->unit->id,
        ]);
        $lock->assertOk();
        $this->assertTrue(collect($lock->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'place_overlock' && $s['trigger'] === 'manual'
        ));
        $this->assertDatabaseHas('unit_holds', [
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Overlock->value,
            'reason' => 'delinquency:'.$id,
            'released_at' => null,
        ]);

        // Record notice + mark sent
        $notice = $this->postJson("/api/delinquencies/{$id}/notices", [
            'notice_type' => ContractNoticeType::FinalDemand->value,
        ]);
        $notice->assertOk();
        $noticeStep = collect($notice->json('data.timeline'))->first(
            fn ($s) => $s['action'] === 'record_notice'
        );
        $this->assertNotNull($noticeStep);
        $noticeId = $noticeStep['contract_notice']['id'];

        $mark = $this->postJson("/api/contract-notices/{$noticeId}/mark-sent", [
            'channel' => 'email',
            'sent_at' => '2026-08-20T10:00:00+02:00',
            'sent_to' => 'tenant@example.com',
        ]);
        $mark->assertOk();
        $mark->assertJsonPath('data.sent_channel', 'email');
        $this->assertNotNull(ContractNotice::query()->findOrFail($noticeId)->sent_at);

        // Pause / resume
        $pause = $this->postJson("/api/delinquencies/{$id}/pause", [
            'reason' => 'customer dispute',
        ]);
        $pause->assertOk();
        $pause->assertJsonPath('data.is_paused', true);
        $this->assertTrue(collect($pause->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'pause' && $s['trigger'] === 'manual'
        ));

        $resume = $this->postJson("/api/delinquencies/{$id}/resume");
        $resume->assertOk();
        $resume->assertJsonPath('data.is_paused', false);
        $this->assertTrue(collect($resume->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'resume'
        ));

        // Release overlock
        $release = $this->postJson("/api/delinquencies/{$id}/release-overlock", [
            'reason' => 'physical lock removed',
        ]);
        $release->assertOk();
        $this->assertTrue(collect($release->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'release_overlock' && $s['trigger'] === 'manual'
        ));
        $this->assertNotNull(
            UnitHold::query()->where('reason', 'delinquency:'.$id)->whereNotNull('released_at')->first()
        );
    }

    public function test_write_off_cures_end_to_end(): void
    {
        $id = $this->case->id;

        $response = $this->postJson("/api/delinquencies/{$id}/write-off", [
            'reason' => 'uncollectible — condonar deuda',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.is_open', false);
        $response->assertJsonPath('data.cure_trigger', DelinquencyCureTrigger::WriteOff->value);

        $this->assertTrue(collect($response->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'write_off' && $s['trigger'] === 'manual'
        ));
        $this->assertTrue(collect($response->json('data.timeline'))->contains(
            fn ($s) => $s['action'] === 'cure' && $s['trigger'] === 'cure'
        ));

        $this->assertDatabaseHas('charges', [
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::WriteOff->value,
        ]);

        $writeOff = Charge::query()
            ->where('contract_id', $this->contract->id)
            ->where('charge_type', ChargeType::WriteOff)
            ->firstOrFail();
        $this->assertTrue(bccomp((string) $writeOff->amount, '0', 2) < 0);

        // Must not reopen on re-evaluation.
        $this->assertNull(
            Delinquency::query()->where('contract_id', $this->contract->id)->open()->first()
        );
    }
}
